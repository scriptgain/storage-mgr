<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Periodic ONLINE license validation against ScriptGain.
 *
 * Complements the offline .lic verifier: instead of a locally-verified file,
 * this calls ScriptGain's validation API (POST /v1/validate) with the stored
 * license key and verifies the RSA-SHA256 signature over the SIGNED response
 * using the SAME canonical routine as OfflineLicenseVerifier. Only a genuinely
 * ScriptGain-signed answer is ever allowed to change the lockdown state.
 *
 * State model (persisted to Settings):
 *   - license_online_state           valid | expired | invalid | stale | null
 *   - license_online_reason          server reason (or unreachable/inconclusive/grace_expired)
 *   - license_online_message         human message
 *   - license_online_checked_at      last time a check ran (any outcome)
 *   - license_online_last_success_at last time a VERIFIED definitive answer arrived
 *   - license_online_last_error(_at) last transport/verification failure
 *   - license_online_expires_at / _product / _seats  from the last verified answer
 *
 * Fail-open by design: an unreachable server keeps the last known state and only
 * escalates to a locked 'stale' after online_grace_days; an unverifiable response
 * (bad signature / MITM) is INCONCLUSIVE and never changes state.
 */
class OnlineLicenseCheck
{
    /** Read-only accessor for the persisted online lock state (nullable). */
    public static function state(): ?string
    {
        $s = Setting::get('license_online_state');

        return ($s === null || $s === '' ) ? null : $s;
    }

    /**
     * Run one validation cycle. Never throws; always returns a result array.
     *
     * @return array{state:?string,reason:?string,message:string,inconclusive?:bool}
     */
    public function check(): array
    {
        $key = trim((string) Setting::get('license_key'));
        if ($key === '') {
            // No key configured -> online licensing is a no-op (safe default).
            return ['state' => null, 'reason' => null, 'message' => 'No license key configured.'];
        }

        $url = (string) config('licensing.validate_url', 'https://scriptgain.com/v1/validate');
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';

        try {
            $resp = Http::timeout(8)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'key' => $key,
                    'hostname' => gethostname() ?: '',
                    'domain' => $domain,
                ]);
        } catch (\Throwable $e) {
            // Network error / timeout -> unreachable (grace applies).
            return $this->unreachable('Validation server unreachable: '.$e->getMessage());
        }

        if (! $resp->successful()) {
            // A non-2xx (gateway/proxy/rate-limit) is not a definitive license
            // answer; treat as unreachable so a hiccup can't lock the panel.
            return $this->unreachable('Validation server returned HTTP '.$resp->status().'.');
        }

        $body = $resp->json();
        if (! is_array($body) || ! isset($body['response']) || ! is_array($body['response']) || ! isset($body['signature'])) {
            return $this->inconclusive('Malformed validation response.');
        }

        // Verify the signature with the SAME canonical routine as the offline path.
        $verifier = new OfflineLicenseVerifier;
        $canonical = $verifier->canonicalize($body['response']);
        $sig = base64_decode((string) $body['signature'], true);
        $ok = $sig !== false
            && openssl_verify($canonical, $sig, $verifier->publicKey(), OPENSSL_ALGO_SHA256) === 1;

        if (! $ok) {
            // Signed by someone other than ScriptGain (or corrupted / MITM proxy).
            // INCONCLUSIVE: do NOT change the lock state.
            return $this->inconclusive('Validation response signature did not verify; ignoring.');
        }

        // Genuine, ScriptGain-signed answer.
        $response = $body['response'];

        if (($response['valid'] ?? null) === true) {
            $this->persistVerified('valid', null, 'License validated online.', $response);

            // Genuine online validation: also fetch + store the signed .lic so the
            // OFFLINE verifier is satisfied too (both status cards then agree).
            // Fail-soft — never affects the online result.
            $this->fetchAndStoreOfflineLicense($key, $url);

            return ['state' => 'valid', 'reason' => null, 'message' => 'License validated online.'];
        }

        $reason = (string) ($response['reason'] ?? 'invalid');
        $state = match ($reason) {
            'expired' => 'expired',
            'revoked', 'suspended', 'not_found', 'invalid' => 'invalid',
            'activation_limit', 'domain_limit' => 'invalid',
            default => 'invalid',
        };
        $message = $this->reasonMessage($reason);
        $this->persistVerified($state, $reason, $message, $response);

        return ['state' => $state, 'reason' => $reason, 'message' => $message];
    }

    /**
     * On a genuine online validation, fetch this instance's signed offline .lic
     * from the vendor and hand it to the OfflineLicenseVerifier so the offline
     * path is satisfied without a manual upload.
     *
     * The offline endpoint is derived from the validate URL:
     *   https://scriptgain.com/v1/validate  ->  https://scriptgain.com/v1
     *   GET {base}/licenses/{key}/offline
     *
     * Strictly fail-soft: any transport error, non-2xx, or empty body is ignored
     * and MUST NOT change or fail the online result.
     */
    private function fetchAndStoreOfflineLicense(string $key, string $validateUrl): void
    {
        try {
            $base = rtrim((string) preg_replace('#/validate/?$#', '', $validateUrl), '/');
            if ($base === '') {
                return;
            }

            $resp = Http::timeout(5)
                ->acceptJson()
                ->get($base.'/licenses/'.rawurlencode($key).'/offline');

            if (! $resp->successful()) {
                return;
            }

            $body = trim((string) $resp->body());
            if ($body !== '') {
                (new OfflineLicenseVerifier)->store($body);
            }
        } catch (\Throwable $e) {
            // Fail-soft: a missing/failed .lic fetch never affects the online result.
        }
    }

    /** Persist a VERIFIED outcome (valid or genuine failure). Resets the grace clock. */
    private function persistVerified(string $state, ?string $reason, string $message, array $response): void
    {
        $now = Carbon::now()->toDateTimeString();
        Setting::put('license_online_state', $state);
        Setting::put('license_online_reason', $reason);
        Setting::put('license_online_message', $message);
        Setting::put('license_online_checked_at', $now);
        Setting::put('license_online_last_success_at', $now); // a definitive signed answer = success
        Setting::put('license_online_expires_at', $response['expires_at'] ?? null);
        Setting::put('license_online_product', $response['product'] ?? null);
        Setting::put('license_online_seats', isset($response['seats']) ? json_encode($response['seats']) : null);
        Setting::put('license_online_last_error', null);
    }

    /**
     * Server unreachable (network error / timeout / non-2xx). Keep the last known
     * online state; only escalate to a locked 'stale' if we have NOT confirmed the
     * license for longer than online_grace_days.
     */
    private function unreachable(string $err): array
    {
        $now = Carbon::now();
        Setting::put('license_online_checked_at', $now->toDateTimeString());
        Setting::put('license_online_last_error', $err);
        Setting::put('license_online_last_error_at', $now->toDateTimeString());

        $graceDays = (int) config('licensing.online_grace_days', 7);
        $lastSuccess = Setting::get('license_online_last_success_at');

        if ($lastSuccess && Carbon::parse($lastSuccess)->addDays($graceDays)->isPast()) {
            $msg = "Could not confirm this license online for more than {$graceDays} days.";
            Setting::put('license_online_state', 'stale');
            Setting::put('license_online_reason', 'grace_expired');
            Setting::put('license_online_message', $msg);

            return ['state' => 'stale', 'reason' => 'grace_expired', 'message' => $msg, 'inconclusive' => true];
        }

        // Within grace (or never validated yet): keep last known state, no lock.
        return ['state' => self::state(), 'reason' => 'unreachable', 'message' => $err, 'inconclusive' => true];
    }

    /**
     * Reachable but the answer could not be verified as ScriptGain's (bad
     * signature, possible MITM/proxy, or malformed). Never changes lock state.
     */
    private function inconclusive(string $err): array
    {
        $now = Carbon::now()->toDateTimeString();
        Setting::put('license_online_checked_at', $now);
        Setting::put('license_online_last_error', $err);
        Setting::put('license_online_last_error_at', $now);

        return ['state' => self::state(), 'reason' => 'inconclusive', 'message' => $err, 'inconclusive' => true];
    }

    private function reasonMessage(string $reason): string
    {
        return match ($reason) {
            'expired' => 'This license has expired.',
            'revoked' => 'This license has been revoked.',
            'suspended' => 'This license is suspended.',
            'not_found' => 'This license key was not found.',
            'activation_limit' => 'This license has exceeded its activation (seat) limit.',
            'domain_limit' => 'This license has exceeded its domain limit.',
            'invalid' => 'This license is not valid.',
            default => 'This license is not valid ('.$reason.').',
        };
    }
}

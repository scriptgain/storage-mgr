<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Offline verification of a ScriptGain-signed .lic license file.
 *
 * Verifies the RSA (SHA-256) signature over the canonical JSON encoding of the
 * license payload against ScriptGain's embedded public key, then evaluates the
 * license state (valid / expired / stale / invalid / tampered). No network is
 * required, which is the whole point: a self-hosted instance can confirm it is
 * entitled to run even while air-gapped.
 *
 * The canonicalization MUST match the signer EXACTLY:
 *   - ksort() the TOP-LEVEL payload keys only (the nested 'features'/'seats'
 *     objects are left untouched),
 *   - json_encode() with JSON_UNESCAPED_SLASHES,
 *   - openssl_verify() with OPENSSL_ALGO_SHA256.
 *
 * The exact same routine (canonicalize()) is reused by OnlineLicenseCheck to
 * verify the signed /v1/validate response.
 */
class OfflineLicenseVerifier
{
    /** ScriptGain license-signing public key (identical across the fleet). */
    private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzFrRFiXb2ClbB+YDkOTj
vwMwJCZ1hC65IJ2rbLNM2zdUzMB/eT/MJ7iL5fFEWFCKytAoAuLr0Gofx2CE3u7y
WILwb+ZUT2eFNctFrWJiL737Cgh3Dx1tQmkveVZvs8elvZ+Kh2Gh8tEbKZ7pW+pl
dZwlHY4gBo3+YiAaYns9mcZuHDNO7Dm6Vn8B3hxYMzJ6lr/qoH/f+ZiT67Lcjzsl
O64X+7D4A0nBGBOVk6h0n8ZkoToXply6Qe0tUz8YWcJ4VJkAnFNlaDPDAl+E4EmL
B8CwKpuG6rsQaopXKP2K+XGXge9oOB25RCTKcQyB0hOqeu61pxwquUkC/iVyxPzH
jwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /** Re-verify the stored .lic at most this often (seconds). */
    private const RECHECK_TTL = 300;

    /** Cache key for the derived lockdown state ('' means "no .lic uploaded"). */
    private const STATE_CACHE_KEY = 'license.offline.state';

    /**
     * The ScriptGain public key. Prefers an on-disk copy at
     * storage/app/scriptgain-pubkey.pem if the operator dropped one in;
     * otherwise the embedded constant. Both are the same key.
     */
    public function publicKey(): string
    {
        $path = storage_path('app/scriptgain-pubkey.pem');
        if (is_readable($path)) {
            $pem = trim((string) file_get_contents($path));
            if ($pem !== '') {
                return $pem;
            }
        }

        return self::PUBLIC_KEY;
    }

    /**
     * Canonical form used for BOTH offline .lic verification and the online
     * /v1/validate response signature: top-level ksort only, JSON_UNESCAPED_SLASHES,
     * nested objects (features/seats) left in received order.
     *
     * @param  array<string,mixed>  $payload
     */
    public function canonicalize(array $payload): string
    {
        ksort($payload); // top-level keys only; nested order preserved
        return json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Verify a raw .lic JSON string.
     *
     * @return array{state:string,payload:?array,message:string}
     */
    public function verify(string $licJson): array
    {
        $doc = json_decode($licJson, true);
        if (! is_array($doc) || ! isset($doc['license']) || ! is_array($doc['license']) || ! isset($doc['signature'])) {
            return $this->result('tampered', null, 'The license file is malformed or unreadable.');
        }

        $payload = $doc['license'];
        $canonical = $this->canonicalize($payload);

        // Prefer the compiled guard: it verifies the RSA signature against the
        // embedded ScriptGain key AND decides the license state in compiled code a
        // customer cannot patch. It implements the identical rules used below, so
        // the accepted set of licenses is unchanged. Falls back to the inline PHP
        // path when the helper is not installed (fail-soft).
        $verdict = LicenseGuard::evaluate($canonical, (string) $doc['signature'], config('licensing.product'));
        if ($verdict !== null) {
            return $this->result($verdict['state'], $payload, $this->stateMessage($verdict['state'], $payload));
        }

        // --- PHP fallback (compiled guard unavailable) ------------------------
        $signature = base64_decode((string) $doc['signature'], true);
        $ok = $signature !== false
            && openssl_verify($canonical, $signature, $this->publicKey(), OPENSSL_ALGO_SHA256) === 1;

        if (! $ok) {
            return $this->result('tampered', $payload, 'The license signature is invalid. The file may have been altered or corrupted.');
        }

        // Signature is genuine — now evaluate the license state.
        $expiresAt = $this->parse($payload['expires_at'] ?? null);
        $offlineExpiresAt = $this->parse($payload['offline_expires_at'] ?? null);
        $valid = ($payload['valid'] ?? null) === true;

        // expires_at === null means perpetual (no hard expiry).
        if ($expiresAt && $expiresAt->isPast()) {
            return $this->result('expired', $payload, 'This license expired on '.$expiresAt->toDayDateTimeString().'.');
        }

        // The offline re-check window ALWAYS applies.
        if ($offlineExpiresAt && $offlineExpiresAt->isPast()) {
            return $this->result('stale', $payload, 'The offline verification window lapsed on '.$offlineExpiresAt->toDayDateTimeString().'. Re-download your license file to keep running offline.');
        }

        if (! $valid) {
            return $this->result('invalid', $payload, 'This license is not active. It may have been revoked or suspended.');
        }

        return $this->result('valid', $payload, 'License valid.');
    }

    /**
     * Verify a raw .lic string and persist the outcome to Settings, refreshing
     * the cached state. Called on upload and from the periodic boot re-check.
     *
     * @return array{state:string,payload:?array,message:string}
     */
    public function store(string $licJson): array
    {
        $r = $this->verify($licJson);
        $p = $r['payload'] ?? [];

        Setting::put('license_lic', $licJson);
        Setting::put('license_state', $r['state']);
        Setting::put('license_message', $r['message']);
        Setting::put('license_checked_at', Carbon::now()->toDateTimeString());
        Setting::put('license_product', $p['product'] ?? null);
        Setting::put('license_type', $p['type'] ?? null);
        Setting::put('license_lic_expires_at', $p['expires_at'] ?? null);
        Setting::put('license_offline_expires_at', $p['offline_expires_at'] ?? null);

        Cache::put(self::STATE_CACHE_KEY, $r['state'], self::RECHECK_TTL);

        return $r;
    }

    /** Forget the uploaded .lic and its derived state (revert to online-key mode). */
    public function forget(): void
    {
        foreach ([
            'license_lic', 'license_state', 'license_message', 'license_product',
            'license_type', 'license_lic_expires_at', 'license_offline_expires_at',
        ] as $k) {
            Setting::put($k, null);
        }

        Cache::forget(self::STATE_CACHE_KEY);
    }

    /**
     * The current OFFLINE lockdown state, resolved cheaply.
     *
     * Returns null when no .lic has been uploaded — the instance then relies on
     * the online check (or nothing). Otherwise re-verifies the stored file at most
     * once per RECHECK_TTL so a license that crosses its expires_at /
     * offline_expires_at flips to expired/stale without a re-upload.
     */
    public static function currentState(): ?string
    {
        try {
            $cached = Cache::get(self::STATE_CACHE_KEY, false);
            if ($cached !== false) {
                return $cached === '' ? null : $cached;
            }

            $raw = Setting::get('license_lic');
            if (empty($raw)) {
                Cache::put(self::STATE_CACHE_KEY, '', self::RECHECK_TTL);

                return null;
            }

            $r = (new self)->verify($raw);

            // Keep the persisted state in sync so the settings page and banner agree.
            if (Setting::get('license_state') !== $r['state']) {
                Setting::put('license_state', $r['state']);
                Setting::put('license_message', $r['message']);
                Setting::put('license_checked_at', Carbon::now()->toDateTimeString());
            }

            Cache::put(self::STATE_CACHE_KEY, $r['state'], self::RECHECK_TTL);

            return $r['state'];
        } catch (\Throwable $e) {
            // Never hard-fail a request over licensing plumbing (e.g. pre-install).
            return null;
        }
    }

    /**
     * The EFFECTIVE lockdown state: the MORE RESTRICTIVE of the offline .lic state
     * and the online validation state, with a human reason. This is what the
     * banner and EnforceLicense middleware consult.
     *
     * Both sources null  -> null  (nothing configured, never locked: safe default).
     * Otherwise the non-null state with the highest lock severity wins.
     *
     * @return array{state:?string,message:?string,source:?string}
     */
    public static function effectiveState(): array
    {
        // Severity: higher = more locked. 'valid' is an explicit "ok" (not a lock).
        $rank = ['valid' => 0, 'stale' => 1, 'expired' => 2, 'invalid' => 3, 'tampered' => 3];

        $candidates = [];

        try {
            $offline = self::currentState();
            if ($offline !== null) {
                $candidates[] = [
                    'state' => $offline,
                    'message' => Setting::get('license_message'),
                    'source' => 'offline',
                ];
            }
        } catch (\Throwable $e) {
            // ignore offline plumbing errors
        }

        try {
            $online = OnlineLicenseCheck::state();
            if ($online !== null) {
                $candidates[] = [
                    'state' => $online,
                    'message' => Setting::get('license_online_message'),
                    'source' => 'online',
                ];
            }
        } catch (\Throwable $e) {
            // ignore online plumbing errors
        }

        if (empty($candidates)) {
            return ['state' => null, 'message' => null, 'source' => null];
        }

        usort($candidates, fn ($a, $b) => ($rank[$b['state']] ?? 0) <=> ($rank[$a['state']] ?? 0));

        return $candidates[0];
    }

    /**
     * The human message for a state returned by the compiled guard. Mirrors the
     * strings the inline PHP path produces so the settings page / banner read
     * identically whichever path decided the state.
     *
     * @param  array<string,mixed>  $payload
     */
    private function stateMessage(string $state, array $payload): string
    {
        $expiresAt = $this->parse($payload['expires_at'] ?? null);
        $offlineExpiresAt = $this->parse($payload['offline_expires_at'] ?? null);

        return match ($state) {
            'expired' => 'This license expired on '.($expiresAt ? $expiresAt->toDayDateTimeString() : 'an earlier date').'.',
            'stale' => 'The offline verification window lapsed'.($offlineExpiresAt ? ' on '.$offlineExpiresAt->toDayDateTimeString() : '').'. Re-download your license file to keep running offline.',
            'invalid' => 'This license is not active. It may have been revoked or suspended.',
            'tampered' => 'The license signature is invalid. The file may have been altered or corrupted.',
            default => 'License valid.',
        };
    }

    private function parse($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function result(string $state, ?array $payload, string $message): array
    {
        return ['state' => $state, 'payload' => $payload, 'message' => $message];
    }
}

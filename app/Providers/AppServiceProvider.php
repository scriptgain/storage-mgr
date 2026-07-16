<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Apply DB-backed branding over config at boot (DB-driven config pattern).
     * Guarded so the app still boots before migrations run.
     */
    public function boot(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
            $s = Setting::map();
            // DB-driven timezone: makes schedules fire and times display in the
            // configured zone (e.g. America/Phoenix) instead of UTC.
            if (! empty($s['timezone'])) {
                config(['app.timezone' => $s['timezone']]);
                date_default_timezone_set($s['timezone']);
            }
            if (! empty($s['brand_name'])) {
                config(['brand.name' => $s['brand_name'], 'app.name' => $s['brand_name']]);
            }
            if (! empty($s['brand_tagline'])) {
                config(['brand.tagline' => $s['brand_tagline']]);
            }
            if (! empty($s['brand_accent'])) {
                config(['brand.accent' => $s['brand_accent']]);
            }
            if (! empty($s['brand_icon'])) {
                config(['brand.icon' => $s['brand_icon']]);
            }
            // Idle session timeout (minutes) -> Laravel session lifetime. Live.
            if (! empty($s['session_timeout_minutes'])) {
                config(['session.lifetime' => (int) $s['session_timeout_minutes']]);
            }
            // Expose General defaults app-wide so forms and agents can read them.
            config([
                'backup.date_format' => $s['date_format'] ?? 'M j, Y',
                'backup.time_format' => $s['time_format'] ?? 'g:i A',
                'backup.rows_per_page' => (int) ($s['rows_per_page'] ?? 25),
                'backup.default_compression' => $s['default_compression'] ?? 'zstd',
                'backup.default_keep_latest' => (int) ($s['default_keep_latest'] ?? 10),
                'backup.prune_after_backup' => ($s['prune_after_backup'] ?? '0') === '1',
                'backup.require_2fa' => ($s['require_2fa'] ?? '0') === '1',
                'backup.force_password_days' => (int) ($s['force_password_days'] ?? 0),
                'backup.run_history_days' => (int) ($s['run_history_days'] ?? 90),
                'backup.audit_log_days' => (int) ($s['audit_log_days'] ?? 180),
                'backup.agent_auto_update' => ($s['agent_auto_update'] ?? '0') === '1',
                'backup.max_concurrent_jobs' => (int) ($s['max_concurrent_jobs'] ?? 2),
                'backup.agent_poll_interval' => (int) ($s['agent_poll_interval'] ?? 30),
                'backup.offline_after_minutes' => (int) ($s['offline_after_minutes'] ?? 5),
                'backup.file_index_cap' => (int) ($s['file_index_cap'] ?? 5000),
            ]);
            // DB-driven mail transport (Email Delivery settings).
            $transport = $s['mail_transport'] ?? null;
            if (! empty($transport)) {
                switch ($transport) {
                    case 'sendgrid':
                        config([
                            'mail.default' => 'smtp',
                            'mail.mailers.smtp.host' => 'smtp.sendgrid.net',
                            'mail.mailers.smtp.port' => 587,
                            'mail.mailers.smtp.username' => 'apikey',
                            'mail.mailers.smtp.password' => $s['sendgrid_api_key'] ?? null,
                            'mail.mailers.smtp.encryption' => 'tls',
                        ]);
                        break;
                    case 'smtp':
                        $enc = $s['smtp_encryption'] ?? 'tls';
                        config([
                            'mail.default' => 'smtp',
                            'mail.mailers.smtp.host' => $s['smtp_host'] ?? null,
                            'mail.mailers.smtp.port' => (int) (($s['smtp_port'] ?? '') ?: 587),
                            'mail.mailers.smtp.username' => $s['smtp_username'] ?? null,
                            'mail.mailers.smtp.password' => $s['smtp_password'] ?? null,
                            'mail.mailers.smtp.encryption' => $enc === 'none' ? null : $enc,
                        ]);
                        break;
                    case 'mail':
                        // Laravel's sendmail mailer -> local MTA / PHP mail().
                        config(['mail.default' => 'sendmail']);
                        break;
                    case 'log':
                    default:
                        config(['mail.default' => 'log']);
                        break;
                }
            } elseif (! empty($s['smtp_host'])) {
                // Legacy fallback: installs that configured SMTP before the
                // Email Delivery transport picker existed.
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $s['smtp_host'],
                    'mail.mailers.smtp.port' => (int) ($s['smtp_port'] ?: 587),
                    'mail.mailers.smtp.username' => $s['smtp_username'] ?? null,
                    'mail.mailers.smtp.password' => $s['smtp_password'] ?? null,
                    'mail.from.address' => $s['mail_from'] ?: ('backups@' . parse_url(config('app.url'), PHP_URL_HOST)),
                    'mail.from.name' => $s['brand_name'] ?? config('brand.name'),
                ]);
            }
            // Sender identity applies to every transport when configured.
            if (! empty($s['mail_from'])) {
                config(['mail.from.address' => $s['mail_from']]);
            }
            if (! empty($s['mail_from_name'])) {
                config(['mail.from.name' => $s['mail_from_name']]);
            } elseif (! empty($s['brand_name'])) {
                config(['mail.from.name' => $s['brand_name']]);
            }
            // Offline license lockdown: cheaply refresh license_state from the
            // stored .lic so a file that crosses its expires_at / offline window
            // flips to expired/stale without a re-upload. Cached (re-verifies at
            // most every few minutes) so this stays free on the hot path.
            \App\Services\OfflineLicenseVerifier::currentState();

            // Opportunistic ONLINE license validation: if the last online check is
            // older than the configured interval, fire one AFTER the response is
            // sent (never blocks the request), guarded so only one runs at a time.
            $this->maybeCheckLicenseOnline($s);
        } catch (\Throwable $e) {
            // DB not ready (e.g. during install); fall back to config defaults.
        }
    }

    /**
     * Queue a background online license check (post-response) when one is due.
     * The scheduled `license:check-online` command is the primary driver; this is
     * a best-effort top-up for instances whose scheduler may be idle.
     *
     * @param  array<string,mixed>  $s  the settings map
     */
    private function maybeCheckLicenseOnline(array $s): void
    {
        try {
            // Nothing to validate without a key.
            if (trim((string) ($s['license_key'] ?? '')) === '') {
                return;
            }

            $intervalDays = (int) config('licensing.online_check_interval_days', 2);
            $checkedAt = $s['license_online_checked_at'] ?? null;
            $due = empty($checkedAt) || Carbon::parse($checkedAt)->addDays($intervalDays)->isPast();
            if (! $due) {
                return;
            }

            // Once-per-window guard across concurrent requests (atomic add).
            if (! Cache::add('license.online.autocheck', 1, 900)) {
                return;
            }

            app()->terminating(function () {
                try {
                    (new \App\Services\OnlineLicenseCheck)->check();
                } catch (\Throwable $e) {
                    // never surface licensing plumbing errors
                } finally {
                    Cache::forget('license.online.autocheck');
                }
            });
        } catch (\Throwable $e) {
            // never break boot over the opportunistic check
        }
    }
}

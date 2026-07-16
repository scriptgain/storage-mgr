<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\OnlineLicenseCheck;
use Illuminate\Console\Command;

/**
 * Periodic online license validation against ScriptGain's signed /v1/validate API.
 * Scheduled every ~2 days (see routes/console.php) and also fired opportunistically
 * from AppServiceProvider. Fail-open: an unreachable server keeps the last known
 * state until the grace window lapses; an unverifiable response never locks.
 */
class LicenseCheckOnline extends Command
{
    protected $signature = 'license:check-online';

    protected $description = "Validate this instance's license key against ScriptGain and update the online lockdown state.";

    public function handle(): int
    {
        $r = (new OnlineLicenseCheck)->check();
        $state = $r['state'] ?? null;
        $reason = $r['reason'] ?? null;

        if ($state === null && $reason === null) {
            $this->info('No license key configured; nothing to validate.');

            return self::SUCCESS;
        }

        $line = 'Online license check: state='.($state ?? 'unchanged').', reason='.($reason ?? 'ok');
        if (! empty($r['inconclusive'])) {
            $line .= ' (inconclusive; state unchanged)';
        }
        $this->info($line);

        try {
            AuditLog::record('license', $line);
        } catch (\Throwable $e) {
            // audit is best-effort
        }

        return self::SUCCESS;
    }
}

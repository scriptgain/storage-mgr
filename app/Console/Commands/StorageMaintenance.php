<?php

namespace App\Console\Commands;

use App\Http\Controllers\MaintenanceController;
use App\Models\AuditLog;
use Illuminate\Console\Command;

class StorageMaintenance extends Command
{
    protected $signature = 'storage:maintenance {--force : Ignore the configured maintenance window}';

    protected $description = 'Recalculate bucket usage, disable stale access keys, and prune old audit rows.';

    public function handle(): int
    {
        if (! $this->option('force') && ! MaintenanceController::allowedNow()) {
            $this->info('Outside the maintenance window; skipping. Use --force to override.');

            return self::SUCCESS;
        }

        $c = MaintenanceController::runSweep();

        $this->info("Maintenance: {$c['buckets_recalced']} bucket(s) recalculated, {$c['keys_disabled']} key(s) disabled, {$c['audit_pruned']} audit row(s) pruned.");
        AuditLog::record('maintenance', "Scheduled maintenance: {$c['buckets_recalced']} buckets recalculated, {$c['keys_disabled']} keys disabled, {$c['audit_pruned']} audit rows pruned");

        return self::SUCCESS;
    }
}

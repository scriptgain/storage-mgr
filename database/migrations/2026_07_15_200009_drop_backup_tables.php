<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the backup-manager domain tables. This app was cloned from the Vaulten
 * backup platform; those concepts (directors, hosts, repositories, backup jobs,
 * runs, restores, snapshots, retention, storage devices, file sync, shares) do
 * not apply to a license manager. Kept: locations, settings, api_tokens,
 * audit_logs, users, and the framework cache/jobs (queue) tables.
 */
return new class extends Migration
{
    private array $tables = [
        'restores', 'runs', 'jobs_catalog', 'schedule_templates', 'storage_devices',
        'sync_folders', 'shares', 'hosts', 'repositories', 'retention_policies', 'directors',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ($this->tables as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // One-way cleanup; the original create migrations remain in history for
        // reference. Restore from the pre-refactor snapshot if you need them back.
    }
};

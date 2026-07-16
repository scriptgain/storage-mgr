<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the license-manager domain tables. This app was cloned from the
 * licensing-management scaffold (itself cloned from the Vaulten backup
 * platform); those concepts (products, features, plans, customers, licenses,
 * activations, license servers, locations) don't apply to StorageMGR, an
 * object-storage console. Kept: settings, api_tokens, audit_logs, users, and
 * the framework cache/jobs (queue) tables.
 */
return new class extends Migration
{
    // Children first (even though FK checks are disabled below, this keeps
    // the list readable/intentional): activations -> licenses; plan_feature
    // -> plans/features; licenses/plans/features -> products; license_servers
    // -> locations.
    private array $tables = [
        'activations', 'plan_feature', 'licenses', 'plans', 'features',
        'customers', 'license_servers', 'locations', 'products',
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

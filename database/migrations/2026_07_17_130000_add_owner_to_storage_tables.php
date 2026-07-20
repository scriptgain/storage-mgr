<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buckets, access keys, and policies become owned resources. Objects
        // inherit their owner from the parent bucket. Null = admin-only.
        //
        // Dated after the 2026_07_15_3000xx create migrations: this shipped
        // originally as 2026_07_14_120000, which ran before those tables
        // existed and broke every fresh install. Guarded so installs that
        // already ran it under the old name re-run it harmlessly.
        foreach (['buckets', 'access_keys', 'policies'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('user_id')->nullable()->after('id')
                    ->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['buckets', 'access_keys', 'policies'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('user_id');
            });
        }
    }
};

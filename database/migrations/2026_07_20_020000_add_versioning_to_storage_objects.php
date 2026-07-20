<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Object versioning.
 *
 * A key stops being one row and becomes a stack of versions, so the unique
 * constraint has to include the version. Objects that predate versioning keep
 * the literal version id "null" — the same sentinel S3 uses for objects written
 * while versioning was off, which keeps their existing storage paths valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_objects', function (Blueprint $table) {
            if (! Schema::hasColumn('storage_objects', 'version_id')) {
                $table->string('version_id', 64)->default('null')->after('key');
            }
            if (! Schema::hasColumn('storage_objects', 'is_latest')) {
                $table->boolean('is_latest')->default(true)->after('version_id');
            }
            if (! Schema::hasColumn('storage_objects', 'is_delete_marker')) {
                $table->boolean('is_delete_marker')->default(false)->after('is_latest');
            }
        });

        // Existing rows are the current version of their key.
        DB::table('storage_objects')->whereNull('version_id')->update(['version_id' => 'null']);
        DB::table('storage_objects')->update(['is_latest' => true]);

        // Create the replacement indexes BEFORE dropping the old unique: the
        // bucket_id foreign key needs some index leading with bucket_id, and
        // MySQL refuses to drop the last one that satisfies it.
        Schema::table('storage_objects', function (Blueprint $table) {
            $table->unique(['bucket_id', 'key', 'version_id'], 'storage_objects_bucket_key_version_unique');
            $table->index(['bucket_id', 'key', 'is_latest'], 'storage_objects_current_idx');
        });

        Schema::table('storage_objects', function (Blueprint $table) {
            try {
                $table->dropUnique('storage_objects_bucket_id_key_unique');
            } catch (\Throwable $e) {
                // Index name varies by install; the composite above is what matters.
            }
        });
    }

    public function down(): void
    {
        Schema::table('storage_objects', function (Blueprint $table) {
            $table->dropUnique('storage_objects_bucket_key_version_unique');
            $table->dropIndex('storage_objects_current_idx');
            $table->dropColumn(['version_id', 'is_latest', 'is_delete_marker']);
            $table->unique(['bucket_id', 'key']);
        });
    }
};

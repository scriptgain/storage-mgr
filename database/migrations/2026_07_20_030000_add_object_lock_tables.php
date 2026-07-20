<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Object Lock (WORM). A locked version cannot be deleted until its retention
 * period expires, and a legal hold blocks deletion indefinitely regardless of
 * retention. Lock state lives per version, since that is what retention
 * protects; it requires versioning to be meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buckets', function (Blueprint $table) {
            if (! Schema::hasColumn('buckets', 'object_lock_enabled')) {
                $table->boolean('object_lock_enabled')->default(false)->after('versioning');
            }
            if (! Schema::hasColumn('buckets', 'default_lock_mode')) {
                $table->string('default_lock_mode', 20)->nullable()->after('object_lock_enabled');
            }
            if (! Schema::hasColumn('buckets', 'default_lock_days')) {
                $table->unsignedInteger('default_lock_days')->nullable()->after('default_lock_mode');
            }
        });

        Schema::table('storage_objects', function (Blueprint $table) {
            if (! Schema::hasColumn('storage_objects', 'lock_mode')) {
                // GOVERNANCE may be bypassed with the right permission;
                // COMPLIANCE cannot be bypassed by anyone, including the owner.
                $table->string('lock_mode', 20)->nullable()->after('is_delete_marker');
            }
            if (! Schema::hasColumn('storage_objects', 'lock_retain_until')) {
                $table->timestamp('lock_retain_until')->nullable()->after('lock_mode');
            }
            if (! Schema::hasColumn('storage_objects', 'legal_hold')) {
                $table->boolean('legal_hold')->default(false)->after('lock_retain_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buckets', function (Blueprint $table) {
            $table->dropColumn(['object_lock_enabled', 'default_lock_mode', 'default_lock_days']);
        });
        Schema::table('storage_objects', function (Blueprint $table) {
            $table->dropColumn(['lock_mode', 'lock_retain_until', 'legal_hold']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-object encryption state. Recorded per object rather than per bucket so
 * enabling encryption later never strands the plaintext already on disk: each
 * object is read back the way it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_objects', function (Blueprint $table) {
            if (! Schema::hasColumn('storage_objects', 'encrypted')) {
                $table->boolean('encrypted')->default(false)->after('etag');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storage_objects', function (Blueprint $table) {
            $table->dropColumn('encrypted');
        });
    }
};

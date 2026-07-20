<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lifecycle rules: expire objects, old versions, and abandoned uploads. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buckets', function (Blueprint $table) {
            if (! Schema::hasColumn('buckets', 'lifecycle')) {
                $table->json('lifecycle')->nullable()->after('tags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buckets', function (Blueprint $table) {
            $table->dropColumn('lifecycle');
        });
    }
};

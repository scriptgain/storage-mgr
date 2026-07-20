<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Object and bucket tags. Beyond being an S3 feature in their own right,
 * clients query object tags during ordinary work (the AWS CLI reads them before
 * a multipart copy), so an endpoint without them fails operations that look
 * unrelated to tagging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_objects', function (Blueprint $table) {
            if (! Schema::hasColumn('storage_objects', 'tags')) {
                $table->json('tags')->nullable()->after('etag');
            }
        });

        Schema::table('buckets', function (Blueprint $table) {
            if (! Schema::hasColumn('buckets', 'tags')) {
                $table->json('tags')->nullable()->after('access');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storage_objects', function (Blueprint $table) {
            if (Schema::hasColumn('storage_objects', 'tags')) {
                $table->dropColumn('tags');
            }
        });
        Schema::table('buckets', function (Blueprint $table) {
            if (Schema::hasColumn('buckets', 'tags')) {
                $table->dropColumn('tags');
            }
        });
    }
};

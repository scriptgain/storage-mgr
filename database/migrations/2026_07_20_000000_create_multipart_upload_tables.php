<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multipart uploads. S3 SDKs split anything past a few megabytes into parts,
 * upload them independently (often in parallel), then ask the server to stitch
 * them together. Parts must be tracked until completion so they can be listed,
 * resumed, overwritten, or abandoned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multipart_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bucket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Not "key": it is reserved in MySQL and needs quoting everywhere.
            $table->string('object_key', 1024);
            $table->string('upload_id', 64)->unique();
            $table->string('content_type', 190)->nullable();
            $table->timestamps();

            $table->index('bucket_id');
        });

        Schema::create('multipart_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('multipart_upload_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('part_number');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('etag', 32)->nullable();
            $table->timestamps();

            // Re-uploading a part replaces it, so the pair must be unique.
            $table->unique(['multipart_upload_id', 'part_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multipart_parts');
        Schema::dropIfExists('multipart_uploads');
    }
};

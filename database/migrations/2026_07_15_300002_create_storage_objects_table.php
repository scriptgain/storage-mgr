<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bucket_id')->constrained()->cascadeOnDelete();
            $table->string('key');                                 // full path/name within the bucket
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('content_type')->nullable();
            $table->string('etag')->nullable();
            $table->timestamp('last_modified')->nullable();
            $table->timestamps();
            $table->unique(['bucket_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_objects');
    }
};

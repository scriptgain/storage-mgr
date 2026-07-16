<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buckets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('region')->default('us-east-1');
            $table->string('access')->default('private');          // private|public
            $table->boolean('versioning')->default(false);
            $table->unsignedBigInteger('quota_bytes')->nullable();  // null = unlimited
            $table->unsignedInteger('object_count')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buckets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('access_key_id', 20)->unique();         // AKIA + 16 upper-alnum
            $table->string('secret_key');                          // shown once on create; treated as sensitive after
            $table->string('status')->default('active');           // active|disabled
            $table->foreignId('policy_id')->nullable()->constrained('policies')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_keys');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key')->unique();
            $table->string('status')->default('active');          // active|suspended|revoked|expired
            $table->unsignedInteger('max_activations')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->json('entitlements')->nullable();             // resolved feature codes granted
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->text('signature')->nullable();               // RSA signature of the canonical payload
            $table->timestamp('signed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};

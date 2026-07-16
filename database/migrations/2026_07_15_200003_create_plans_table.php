<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('interval')->default('one_time');     // one_time|monthly|yearly
            $table->unsignedInteger('max_activations')->default(1);
            $table->unsignedInteger('expiry_days')->nullable();  // null = perpetual
            $table->timestamps();
            $table->unique(['product_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};

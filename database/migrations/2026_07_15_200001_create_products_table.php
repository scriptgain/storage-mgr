<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('key_prefix')->nullable();            // e.g. ACME -> ACME-XXXX-...
            $table->unsignedInteger('default_max_activations')->default(1);
            $table->unsignedInteger('default_expiry_days')->nullable();  // null = perpetual
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

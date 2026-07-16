<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic assignment pivot: extends single-owner (user_id) resources so a
 * resource can additionally be made visible to any number of named users.
 * The owner keeps primary ownership; assignees are extra viewers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->morphs('assignable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['assignable_type', 'assignable_id', 'user_id'], 'assignments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};

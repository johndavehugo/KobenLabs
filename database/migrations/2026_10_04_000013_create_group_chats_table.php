<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat groups, joined by a short shareable code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_chats', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('join_code', 8)->unique();
            $table->foreignId('created_by_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('created_by_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_chats');
    }
};

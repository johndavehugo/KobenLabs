<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct messages.
 *
 * NOTE: both the admin and student "Messages" navigation entries in the legacy
 * UI rendered group chat, not this table. Nothing in the legacy frontend reads
 * it. See docs/migration/README.md §7 before building UI on top of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('is_broadcast')->default(false);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('messages')->cascadeOnDelete();

            $table->index('sender_id');
            $table->index('created_at');
            $table->index('is_broadcast');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

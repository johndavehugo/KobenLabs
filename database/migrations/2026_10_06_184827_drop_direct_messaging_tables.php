<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes direct messaging entirely.
 *
 * Group chat replaces it. A teacher sharing a screenshot, a diagram or a block of code
 * with a class mid-lesson needs one room everybody can see, not thirty inboxes to open one
 * at a time - and one-to-one threads between a teacher and a single student made a lesson
 * feel like surveillance rather than support, which is not what this system is for.
 *
 * The three migrations that created these tables have been deleted rather than paired
 * with this one, so a database built from scratch never has them. This exists for
 * databases that were already built, which is every deployment that ran the earlier
 * migrations.
 *
 * Dropped children first. MySQL refuses to drop a table an existing foreign key
 * references, and `message_attachments` and `message_recipients` both point at
 * `messages`; the error names the constraint rather than the table, so dropping the
 * parent first fails with something that reads like a permissions problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('message_recipients');
        Schema::dropIfExists('messages');
    }

    public function down(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('is_broadcast')->default(false);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();

            $table->index('sender_id');
            $table->index('created_at');
            $table->index('is_broadcast');
        });

        Schema::create('message_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            $table->index('read_at');
        });

        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime_type', 100)->nullable();
            $table->timestamps();
        });
    }
};

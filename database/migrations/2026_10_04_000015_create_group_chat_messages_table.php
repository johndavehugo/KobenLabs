<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group chat messages.
 *
 * `sender_id` is nullable by design — system messages generated on join and
 * leave have no author. `file_size` is widened from the legacy `int` to an
 * unsigned bigint to match every other file column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('type', 20)->default('text');
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            $table->timestamps();

            $table->foreign('reply_to_id')->references('id')->on('group_chat_messages')->nullOnDelete();

            $table->index('sender_id');
            $table->index('reply_to_id');
            $table->index(['group_chat_id', 'created_at'], 'group_chat_messages_chat_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_chat_messages');
    }
};

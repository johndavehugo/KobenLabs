<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group membership, modelled as a pivot.
 *
 * `role` is group-scoped and independent of the user's global role: a student
 * can administer a group, and a lab administrator can be a plain member. The
 * group creator is always enrolled as a GroupRole::Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_chat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->timestamp('joined_at')->useCurrent();

            $table->unique(['group_chat_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_chat_members');
    }
};

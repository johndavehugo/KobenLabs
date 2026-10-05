<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PC occupancy history — who sat at which lab machine, and for how long.
 *
 * Named `pc_sessions` because Laravel's own DB session driver already owns the
 * `sessions` table name. The legacy schema used the bare name, which collides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pc_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('terminated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('termination_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
            $table->index('terminated_by_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_sessions');
    }
};

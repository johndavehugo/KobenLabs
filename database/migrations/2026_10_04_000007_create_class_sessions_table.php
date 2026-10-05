<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A live teaching session started from a schedule.
 *
 * The legacy app allowed exactly one `active` row globally and enforced it in
 * controller code, returning 409 for the second attempt. That rule is global
 * rather than per-section, and is configurable via `lab.class_sessions
 * .single_active_session` — enforce it in the service layer rather than with a
 * constraint, since MySQL has no partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('started_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
            $table->index('started_by_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};

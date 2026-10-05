<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class schedules / reservations.
 *
 * Changes from the legacy schema:
 *   - drops `user_id`. It duplicated `teacher_id`, and the two were used
 *     inconsistently: Schedule::getAll() joined on `user_id` while
 *     ClassSessionController::history() filtered on `teacher_id`.
 *   - renames `section_id` to `year_section_id` and `start_datetime` /
 *     `end_datetime` to `starts_at` / `ends_at`.
 *
 * Conflict detection is not enforced here — see Schedule::conflictsWith().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('year_section_id')->nullable()->constrained('year_sections')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('purpose', 200)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();

            $table->index('teacher_id');
            $table->index('year_section_id');
            $table->index('starts_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};

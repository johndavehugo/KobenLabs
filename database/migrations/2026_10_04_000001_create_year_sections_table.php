<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic year and section catalogue.
 *
 * Replaces the legacy free-text `year` / `section` strings that were
 * denormalised onto `users`. Deletion is a soft `is_active = false` so that
 * historical attendance rows keep resolving to a section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('year_sections', function (Blueprint $table) {
            $table->id();
            $table->string('year', 50);
            $table->string('section', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['year', 'section']);
            $table->index('year');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('year_sections');
    }
};

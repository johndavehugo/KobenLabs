<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user display accessibility preferences.
 *
 * The legacy API exposed these at `/api/users/{id}/accessibility` with no
 * ownership check, so any authenticated user could read or write anyone else's
 * preferences. Scope all access to the authenticated user going forward.
 *
 * See docs/migration/README.md §7 — the UI that consumed these settings was
 * dead code, so decide whether to build it before exposing this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accessibility_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('text_size', 20)->default('normal');
            $table->boolean('high_contrast')->default(false);
            $table->boolean('text_to_speech')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessibility_settings');
    }
};

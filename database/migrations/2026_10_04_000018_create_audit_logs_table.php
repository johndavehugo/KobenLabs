<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail.
 *
 * This table existed in the legacy schema but was never read or written — the
 * application logged audit entries as JSON lines to `logs/audit.log` instead.
 * Keeping the table and wiring it up is deliberate: the shape is right, it just
 * needs an observer.
 *
 * `user_id` is nullable with ON DELETE SET NULL so the audit row outlives the
 * account it describes. `properties` is JSON rather than the legacy TEXT so it
 * can be queried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('auditable_type', 100)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index('created_at');
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

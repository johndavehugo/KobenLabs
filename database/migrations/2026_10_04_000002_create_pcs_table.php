<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lab PC inventory.
 *
 * A PC is identified by its IP address: login auto-detects a matching
 * `REMOTE_ADDR` and flips the row online. `mac_address` is reference data for
 * administrators and is intentionally nullable — the MAC-as-identity plan in
 * the legacy repo was reverted.
 *
 * The legacy schema carried both `UNIQUE ip_address` and a redundant
 * `idx_ip_address`. The unique index already serves lookups, so only the
 * unique constraint is declared here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('ip_address', 45)->unique();
            $table->string('mac_address', 17)->nullable();
            $table->string('status', 20)->default('offline');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pcs');
    }
};

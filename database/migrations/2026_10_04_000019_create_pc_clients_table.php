<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrolment for the Lab Client — the background service that puts a picture of a
 * PC's screen on the staff Live Screens page.
 *
 * The legacy app had no such table at all. `agent_register` on its WebSocket relay
 * was accepted from anything that connected, with no credential, which meant any
 * host on the lab network could inject a fake PC's screen into a teacher's grid.
 * Here a row is created by an administrator (`pcs:enrol-client`), carries a
 * credential, and a client that presents no valid credential is refused.
 *
 * `token_hash` rather than `token`, deliberately. The plaintext is shown once at
 * enrolment and never stored, so a database disclosure cannot be replayed as a
 * lab PC — same reasoning as Laravel Sanctum's hashed personal access tokens.
 *
 * One row per PC (`pc_id` is unique) because a machine either has the client
 * running or it does not, and re-enrolling replaces the credential in place.
 *
 * There are no monitoring session tables. Frames are overwritten in place on disk
 * and never accumulated, so there is nothing to accumulate metadata *about* per
 * session here; who viewed which PC is recorded in the audit trail instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pc_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();

            // 'active' or 'revoked'. Revoking keeps the row so the history of the
            // PC's enrolment is not erased, and so the token stays dead rather
            // than becoming reusable if the column were ever cleared.
            $table->string('state', 20)->default('active');

            // Reported at enrolment, for working out which machine in a fleet is
            // misbehaving. Never used for identity — the token is.
            $table->string('hostname', 255)->nullable();
            $table->string('client_version', 20)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_frame_at')->nullable();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'last_seen_at'], 'pc_clients_state_seen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_clients');
    }
};

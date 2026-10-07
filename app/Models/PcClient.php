<?php

namespace App\Models;

use App\Enums\PcClientState;
use Database\Factories\PcClientFactory;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An enrolled Lab Client.
 *
 * This is a machine, not a person, but it authenticates through the normal guard
 * stack (`pc_client`, registered via Auth::viaRequest) so that route middleware,
 * `$request->user('pc_client')` and the rest of the framework behave normally.
 * There is no session and no password: the credential is a bearer token that is
 * hashed at rest and compared by lookup.
 *
 * `token_hash` is a SHA-256 hex digest, so lookups are indexed equality on a fixed
 * 64-character column. Hashing before the lookup also means the comparison never
 * depends on the plaintext's timing.
 */
#[Fillable(['pc_id', 'token_hash', 'state', 'hostname', 'client_version', 'last_seen_at', 'last_frame_at', 'enrolled_at', 'revoked_at'])]
class PcClient extends Model implements AuthenticatableContract
{
    /** @use HasFactory<PcClientFactory> */
    use HasFactory;

    /**
     * Mint a token. 40 characters of base64url, ~240 bits — enough that guessing is
     * not a threat, which is what lets the credential be simple to paste into a
     * config file on a lab PC.
     */
    public const TOKEN_LENGTH = 40;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => PcClientState::class,
            'last_seen_at' => 'datetime',
            'last_frame_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Guard contract
    //
    // Present because the interface demands them. A client has no password and
    // never uses "remember me" — it re-presents its token on every request — so
    // these return inert values rather than pretending to be meaningful.
    // -----------------------------------------------------------------

    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'token_hash';
    }

    public function getAuthPassword(): string
    {
        return $this->token_hash;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Intentionally empty — see getRememberToken().
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function pc(): BelongsTo
    {
        return $this->belongsTo(Pc::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    #[Scope]
    public function active(Builder $query): void
    {
        $query->where('state', PcClientState::Active);
    }

    #[Scope]
    public function revoked(Builder $query): void
    {
        $query->where('state', PcClientState::Revoked);
    }

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Resolve a bearer token to a client, or null.
     *
     * Refuses anything not in the active state, so a revoked token cannot post
     * frames even though its hash is still on file.
     */
    public static function findByToken(?string $token): ?static
    {
        if ($token === null || $token === '') {
            return null;
        }

        return static::query()
            ->active()
            ->where('token_hash', static::hashToken($token))
            ->first();
    }

    /**
     * Generate a token without touching the database.
     *
     * Split from issueToken() because token_hash is NOT NULL: a brand new row has to
     * carry a digest on its first insert, so enrolment cannot save-then-hash.
     */
    public static function generateToken(): string
    {
        return substr(
            strtr(base64_encode(random_bytes(64)), '+/', '-_'),
            0,
            static::TOKEN_LENGTH,
        );
    }

    /**
     * Issue a fresh credential on an existing row, replacing any previous one. Returns
     * the plaintext because this is the only moment it exists — it is never stored.
     *
     * For a row that does not exist yet, use generateToken() and set token_hash before
     * the first save.
     */
    public function issueToken(): string
    {
        $token = static::generateToken();

        $this->forceFill(['token_hash' => static::hashToken($token)])->save();

        return $token;
    }

    public function revoke(): void
    {
        $this->forceFill([
            'state' => PcClientState::Revoked,
            'revoked_at' => now(),
        ])->save();
    }

    // -----------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->state === PcClientState::Active;
    }

    /**
     * Whether the client has stopped checking in.
     *
     * Deliberately separate from `Pc::isStale()`. That one answers "is anyone
     * sitting at this machine" and drives the occupancy dashboard; a Lab Client is
     * a service that runs whether or not a student has logged in. Conflating them
     * would let an idle-but-running client keep a PC looking occupied.
     */
    public function isSilent(?int $thresholdSeconds = null): bool
    {
        if ($this->last_seen_at === null) {
            return true;
        }

        $threshold = $thresholdSeconds ?? (int) config('lab.monitoring.client_silent_after_seconds');

        return $this->last_seen_at->lt(now()->subSeconds($threshold));
    }

    /**
     * Whether the stored frame is too old to show. A PC that has stopped uploading
     * should read as offline rather than displaying a frozen image that looks live.
     */
    public function hasFreshFrame(?int $thresholdSeconds = null): bool
    {
        if ($this->last_frame_at === null) {
            return false;
        }

        $threshold = $thresholdSeconds ?? (int) config('lab.monitoring.frame_stale_after_seconds');

        return $this->last_frame_at->greaterThanOrEqualTo(now()->subSeconds($threshold));
    }

    public function touchSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function touchFrame(Carbon $at): void
    {
        $this->forceFill(['last_frame_at' => $at])->saveQuietly();
    }
}

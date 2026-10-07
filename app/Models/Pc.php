<?php

namespace App\Models;

use App\Enums\PcSessionStatus;
use App\Enums\PcStatus;
use Database\Factories\PcFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'ip_address', 'mac_address', 'status', 'last_seen_at'])]
class Pc extends Model
{
    /** @use HasFactory<PcFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PcStatus::class,
            'last_seen_at' => 'datetime',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PcSession::class);
    }

    public function activeSessions(): HasMany
    {
        return $this->sessions()->where('status', PcSessionStatus::Active);
    }

    /**
     * The enrolled Lab Client for this machine, if any.
     *
     * A hasOne rather than a morph or a through, because pc_clients.pc_id is unique —
     * a machine either has the client running or it does not, and re-enrolling
     * replaces the row.
     *
     * Note this is about the *service*, not about occupancy. `activeSessions()` says
     * whether a person is logged in; this says whether anything is reporting a screen.
     */
    public function client(): HasOne
    {
        return $this->hasOne(PcClient::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    #[Scope]
    public function ofStatus(Builder $query, PcStatus $status): void
    {
        $query->where('status', $status);
    }

    #[Scope]
    public function online(Builder $query): void
    {
        $query->where('status', PcStatus::Online);
    }

    // -----------------------------------------------------------------
    // Behaviour
    // -----------------------------------------------------------------

    public function isOnline(): bool
    {
        return $this->status === PcStatus::Online;
    }

    /**
     * Normalise a MAC address to AA:BB:CC:DD:EE:FF. Reference data only — PC
     * identity is the IP address.
     */
    public static function normaliseMac(?string $mac): ?string
    {
        if ($mac === null || trim($mac) === '') {
            return null;
        }

        return strtoupper(str_replace('-', ':', trim($mac)));
    }

    public static function isValidMac(?string $mac): bool
    {
        return $mac !== null
            && preg_match('/^([0-9A-F]{2}:){5}([0-9A-F]{2})$/', $mac) === 1;
    }

    /**
     * Look up the PC a request came from. Loopback addresses are ignored so a
     * developer working on the server host does not flip a lab machine online.
     */
    public static function detectFromRequest(?string $ipAddress): ?static
    {
        if ($ipAddress === null || $ipAddress === '') {
            return null;
        }

        if (config('lab.pcs.ignore_loopback') && in_array($ipAddress, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        return static::query()->where('ip_address', $ipAddress)->first();
    }

    public function markOnline(): void
    {
        $this->forceFill([
            'status' => PcStatus::Online,
            'last_seen_at' => now(),
        ])->save();
    }

    public function markOffline(): void
    {
        $this->forceFill([
            'status' => PcStatus::Offline,
            'last_seen_at' => null,
        ])->save();
    }

    public function touchLastSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    /**
     * Whether the stale sweep should collect this PC. Maintenance PCs are
     * exempt so an administrator can hold a machine offline without logging in
     * repeatedly.
     */
    public function isStale(int $thresholdMinutes): bool
    {
        if (! $this->status->isAutoOfflinable()) {
            return false;
        }

        return $this->last_seen_at === null
            || $this->last_seen_at->lt(now()->subMinutes($thresholdMinutes));
    }
}

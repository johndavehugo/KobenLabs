<?php

namespace App\Enums;

/**
 * How a lab PC's Lab Client is doing, for display.
 *
 * Display-only: it is derived from the pc_clients row at render time rather than
 * stored, because "reporting" depends on whether a frame is *currently* fresh, which
 * changes with every upload and every silent period. Persisting it would mean a stale
 * badge that says "reporting" for a machine that stopped an hour ago — the same lie as
 * serving a frozen image on the Live Screens grid.
 *
 * Deliberately distinct from PcClientState, which records whether a credential is
 * allowed to post. This one adds the two states that only mean something to a human
 * looking for machines to fix: installed-but-never-reported, and reporting-then-stopped.
 */
enum LabClientState: string
{
    case NotEnrolled = 'not_enrolled';
    case NeverSeen = 'never_seen';
    case Silent = 'silent';
    case Reporting = 'reporting';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::NotEnrolled => 'Not enrolled',
            self::NeverSeen => 'Never checked in',
            self::Silent => 'Silent',
            self::Reporting => 'Reporting',
            self::Revoked => 'Revoked',
        };
    }

    /**
     * Filament colour tokens, matching PcStatus and PcClientState.
     */
    public function color(): string
    {
        return match ($this) {
            self::Reporting => 'success',
            self::NeverSeen, self::Silent => 'warning',
            self::Revoked => 'danger',
            self::NotEnrolled => 'gray',
        };
    }

    /**
     * What an administrator should do about it.
     *
     * Null for Reporting — that is the expected state, there is nothing to fix. The
     * return type is nullable to say that honestly rather than returning an empty
     * string or a filler phrase.
     *
     * Lives on the enum rather than in a template so the badge, the status panel and
     * any future notification all read the same wording instead of drifting into
     * three slightly different sentences.
     */
    public function guidance(): ?string
    {
        return match ($this) {
            self::NotEnrolled => 'Run the Lab Client installer on this machine.',
            self::NeverSeen => 'Installed, but has never reached the server. Check the code and the server URL.',
            self::Silent => 'Was reporting and has stopped. Check the machine.',
            self::Reporting => null,
            self::Revoked => 'Enrol it again to bring it back.',
        };
    }

    /**
     * Whether this state is worth telling an administrator about at all.
     */
    public function needsAttention(): bool
    {
        return $this !== self::Reporting;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Services;

use App\Models\PcClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Stores and serves the latest screen frame for each lab PC.
 *
 * One file per PC, overwritten on every upload. There is deliberately no history:
 *
 * - A grid at 1 fps across 40 PCs writes ~4 MB/s. Keeping even a short ring buffer
 *   would turn a live view into a disk-fill problem, and the feature has no use for
 *   replay — "what was this student doing at 10:15" is not something this answers.
 * - Who watched whom is recorded in the audit trail as metadata. Frame bytes are
 *   never retained, which keeps the privacy surface to "someone was looking at a
 *   screen right now" rather than "a server holds a week of recordings".
 *
 * This is also not a general file store. `FileService` handles user uploads, where
 * the bytes arrive as an UploadedFile with an extension and a quota; a frame arrives
 * as a raw request body from a machine, and is validated on its magic bytes alone.
 */
class ScreenFrameService
{
    /**
     * JPEG start-of-image marker. Every JPEG begins FF D8 FF; a body without it is
     * not a JPEG whatever the Content-Type claimed.
     */
    private const JPEG_MAGIC = "\xFF\xD8\xFF";

    public function disk(): Filesystem
    {
        return Storage::disk(config('lab.storage.default_disk'));
    }

    /**
     * Storage path for a PC's current frame.
     *
     * Keyed by pc_id rather than by name or IP, so a rename or a DHCP change cannot
     * orphan the file, and so one PC cannot overwrite another's frame by guessing a
     * path.
     */
    public function pathFor(int $pcId): string
    {
        return "monitoring/pcs/{$pcId}/latest.jpg";
    }

    /**
     * Accept a frame from a client.
     *
     * @throws ValidationException when the body is empty, too large, or not a JPEG.
     */
    public function store(PcClient $client, string $bytes): void
    {
        $maxBytes = (int) config('lab.monitoring.frame_max_kilobytes') * 1024;

        if ($bytes === '') {
            throw ValidationException::withMessages([
                'frame' => 'The frame body was empty.',
            ]);
        }

        // Checked before parsing the bytes, so an oversized body is rejected without
        // being examined.
        if (strlen($bytes) > $maxBytes) {
            throw ValidationException::withMessages([
                'frame' => sprintf(
                    'The frame is %s KB, over the %s KB limit.',
                    intdiv(strlen($bytes), 1024),
                    intdiv($maxBytes, 1024),
                ),
            ]);
        }

        // Never the client's Content-Type. The Lab Client is trusted to be the app
        // we shipped, but "trusted" is exactly the assumption that gets exploited by
        // whatever is actually listening on that address — so the bytes decide.
        if (! str_starts_with($bytes, self::JPEG_MAGIC)) {
            throw ValidationException::withMessages([
                'frame' => 'The frame was not a JPEG image.',
            ]);
        }

        $this->disk()->put($this->pathFor($client->pc_id), $bytes);
    }

    public function exists(PcClient $client): bool
    {
        return $this->disk()->exists($this->pathFor($client->pc_id));
    }

    /**
     * Whether a frame is present *and* recent enough to show.
     *
     * Both halves matter. The file is what is on disk; the timestamp is what says it
     * is current. Serving a stale file would leave a frozen image on the grid that
     * looks exactly like a live one, which is the failure mode legacy had and never
     * detected.
     */
    public function hasDisplayableFrame(PcClient $client): bool
    {
        return $client->hasFreshFrame() && $this->exists($client);
    }

    public function read(PcClient $client): ?string
    {
        $path = $this->pathFor($client->pc_id);

        if (! $this->disk()->exists($path)) {
            return null;
        }

        $contents = $this->disk()->get($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * A cheap validator for the stored file, so a response can be revalidated and
     * skipped when the client's browser already has these bytes.
     */
    public function fingerprint(PcClient $client): ?string
    {
        $path = $this->pathFor($client->pc_id);

        if (! $this->disk()->exists($path)) {
            return null;
        }

        $lastModified = $this->disk()->lastModified($path);

        return $lastModified === null ? null : (string) $lastModified;
    }

    public function delete(PcClient $client): void
    {
        $path = $this->pathFor($client->pc_id);

        if ($this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    /**
     * Remove a frame without touching the client record. Used by the revoke command
     * so a decommissioned machine's last screen does not linger on disk.
     */
    public function deleteForPc(int $pcId): void
    {
        $path = $this->pathFor($pcId);

        if ($this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    /**
     * The capture cadence to hand back to a client.
     *
     * Returned as a header rather than configured on the client so the rate is
     * governed in one place — config/lab.php — and a whole lab can be slowed down
     * without touching the machines.
     */
    public function intervalSeconds(): float
    {
        return max(0.2, (float) config('lab.monitoring.interval_seconds'));
    }
}

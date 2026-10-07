<?php

use App\Models\ClassSession;
use App\Models\GroupChat;
use App\Models\Pc;
use App\Models\PcClient;
use App\Models\Schedule;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\YearSection;

return [

    /*
    |--------------------------------------------------------------------------
    | Computer Lab Management
    |--------------------------------------------------------------------------
    |
    | Settings for the computer laboratory management system.
    |
    | The legacy application declared these values in four places that all
    | disagreed with one another: `config/constants.php`, `config/config.php`,
    | a seeded but never-read `system_config` table, and hardcoded literals
    | inside the individual controllers. That table has been retired; this
    | file is now the single source of truth.
    |
    */

    'session' => [
        /*
         * Legacy effective value was a 10 minute sliding window (gc_maxlifetime
         * of 600s, kept alive by a 5 minute heartbeat). Its documentation claimed
         * 10, 30, 30 and 60 minutes in four different places, none of which
         * matched the code.
         *
         * Settled at 120 minutes. Filament 5 has no per-panel auth timeout — the
         * panel's `authTimeout()` was removed — so the session lifetime is the
         * only control and there is nothing for it to drift against. Keep
         * SESSION_LIFETIME in .env in step if you change this.
         */
        'lifetime_minutes' => (int) env('LAB_SESSION_LIFETIME', 120),
    ],

    'pcs' => [
        // Identified by IP address. The MAC-based identity proposed in the
        // legacy `plans/mac-address-implementation.md` was reverted and
        // `mac_address` is reference data only.
        'identify_by_ip' => true,

        // Loopback logins are ignored so a developer on the host machine does
        // not flip a lab PC online.
        'ignore_loopback' => true,

        // An online PC that has not checked in within this window is marked
        // offline by `pcs:mark-stale-offline`. Maintenance PCs are exempt.
        'stale_after_minutes' => (int) env('LAB_PC_STALE_MINUTES', 10),
    ],

    'schedules' => [
        'min_duration_minutes' => (int) env('LAB_SCHEDULE_MIN_MINUTES', 30),

        // Legacy enforced 480 in ScheduleController, declared 240 in
        // config/config.php and 240 again in system_config.
        'max_duration_minutes' => (int) env('LAB_SCHEDULE_MAX_MINUTES', 480),
    ],

    'class_sessions' => [
        // The legacy app allowed exactly one active class session globally,
        // enforced in application code. Set to false to allow concurrent
        // sessions across different sections.
        'single_active_session' => true,
    ],

    'storage' => [
        'student_quota_mb' => (int) env('LAB_STUDENT_QUOTA_MB', 500),
        'teacher_quota_mb' => (int) env('LAB_TEACHER_QUOTA_MB', 2000),
        'admin_quota_mb' => (int) env('LAB_ADMIN_QUOTA_MB', 5000),

        // The legacy `storage_used_mb` counter was only incremented for user
        // file uploads and never for message or group chat attachments, so it
        // drifts. Quota checks use this computed total instead.
        'default_disk' => env('LAB_STORAGE_DISK', 'local'),
    ],

    'uploads' => [
        'max_kilobytes' => (int) env('LAB_UPLOAD_MAX_KB', 50 * 1024),

        // Enforced with a MIME sniff of the stored bytes, never the
        // client-supplied Content-Type.
        'allowed_extensions' => [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'txt', 'csv', 'md',
            'zip', 'rar', '7z',
        ],

        // Group chat uploads were the only zone in the legacy app with a real
        // type allowlist. Kept stricter deliberately.
        'group_chat' => [
            'max_kilobytes' => (int) env('LAB_GROUP_UPLOAD_MAX_KB', 10 * 1024),
            'allowed_extensions' => [
                'pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar',
            ],
        ],
    ],

    'group_chats' => [
        'join_code_length' => 8,
        'max_message_length' => (int) env('LAB_GROUP_MESSAGE_MAX', 2000),
        'max_files_per_message' => 5,
        'messages_per_page' => 50,

        // How long a typing indicator stays lit after the last keystroke. Without a decay
        // the label would stick indefinitely if someone were removed from the group
        // mid-sentence, or their browser simply went away.
        'typing_expires_after_seconds' => (int) env('LAB_GROUP_TYPING_TTL', 6),
    ],

    'attendance' => [
        'history_per_page' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Screens
    |--------------------------------------------------------------------------
    |
    | The Lab Client is the background service on each lab PC that puts a picture
    | of its screen on the staff Live Screens page.
    |
    | Legacy streamed 20 fps at 1280px over an unauthenticated WebSocket relay that
    | every teacher connected to. At that rate a 40-PC lab pushed 520-960 Mbps
    | inbound *before* the relay fanned each frame out again. The defaults below are
    | roughly twenty times cheaper, which is the difference between this working at
    | realistic lab size and not.
    |
    */

    'monitoring' => [
        // Master switch. When false the frame endpoint refuses every client, which
        // is the off switch if this needs disabling without uninstalling the client
        // from each machine.
        'enabled' => (bool) env('LAB_MONITORING_ENABLED', true),

        /*
         * How often a client uploads a frame. The server is the authority: it
         * returns the effective interval in the X-Frame-Interval response header,
         * so changing this here retunes every enrolled client without touching
         * them. 1 fps is the grid's needs — a teacher is not watching 40 streams
         * for their fine detail, they are watching one.
         */
        'interval_seconds' => (float) env('LAB_MONITORING_INTERVAL', 1.0),

        // Capture width. Height follows the aspect ratio. 1280 matches legacy's
        // tuned value; dropping to 640 roughly halves bandwidth again and is the
        // first thing to try on an older network.
        'max_width' => (int) env('LAB_MONITORING_WIDTH', 1280),

        // 60 was legacy's tuned value for legible text. Raise to 80 for sharper
        // output at roughly 5ms/frame more CPU on the lab PC.
        'jpeg_quality' => (int) env('LAB_MONITORING_QUALITY', 60),

        // Frames are posted as a raw body, not a multipart upload, so this is a
        // body-size ceiling rather than a FileService upload rule. 512 KB is
        // roughly 5x a 1280px q60 frame, leaving room without inviting abuse.
        'frame_max_kilobytes' => (int) env('LAB_MONITORING_FRAME_MAX_KB', 512),

        /*
         * A client that has not checked in within this window is treated as
         * silent. Separate from lab.pcs.stale_after_minutes on purpose: that
         * answers "is anyone using this machine", this answers "is the client
         * running". A client on an idle PC is working correctly.
         */
        'client_silent_after_seconds' => (int) env('LAB_MONITORING_CLIENT_SILENT', 30),

        /*
         * A stored frame older than this is not shown. Without this a PC that
         * stopped uploading would display its last image indefinitely, reading as
         * live when nothing has happened for an hour.
         */
        'frame_stale_after_seconds' => (int) env('LAB_MONITORING_FRAME_STALE', 15),

        // Record who viewed which PC, and when. Metadata only — never frame bytes.
        'audit' => (bool) env('LAB_MONITORING_AUDIT', true),
    ],

    'audit' => [
        // Dispatch audit writes through the queue so logging never blocks a
        // request. The legacy audit_logs table was created but never written to.
        'queue' => env('LAB_AUDIT_QUEUE', 'default'),

        /*
         * Models whose create/update/delete is recorded in audit_logs.
         *
         * Deliberately an explicit list rather than "everything". Attendance,
         * PC sessions and chat messages are high-volume and would bury the rows
         * that matter — a promoted user, a deleted drive, a changed schedule.
         * Add a model here when a change to it would need explaining afterwards.
         */
        'models' => [
            User::class,
            Pc::class,
            PcClient::class,
            Schedule::class,
            ClassSession::class,
            YearSection::class,
            StoredFile::class,
            GroupChat::class,
        ],

        /*
         * Attributes that must never reach audit_logs. Password hashes are the
         * important one: an audit trail is readable by staff.
         *
         * `token_hash` is listed by its real column name, not as `token`. Redaction
         * is an exact array_key_exists match, not a substring one, so listing `token`
         * alone would silently leave the Lab Client's credential digest in a table
         * any staff member can read — and a digest that lands in an export or a
         * backup is a credential that has to be rotated.
         */
        'redact' => ['password', 'remember_token', 'secret', 'token', 'token_hash'],
    ],

];

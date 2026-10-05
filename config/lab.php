<?php

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
        // Legacy effective value was a 10 minute sliding window (gc_maxlifetime
        // of 600s, kept alive by a 5 minute heartbeat). The docs variously
        // claimed 10, 30, 30 and 60 minutes.
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
    ],

    'messages' => [
        'max_attachment_kilobytes' => (int) env('LAB_MESSAGE_ATTACHMENT_MAX_KB', 50 * 1024),
        'per_page' => 25,
    ],

    'attendance' => [
        'history_per_page' => 25,
    ],

    'audit' => [
        // Dispatch audit writes through the queue so logging never blocks a
        // request. The legacy audit_logs table was created but never written to.
        'queue' => env('LAB_AUDIT_QUEUE', 'default'),
    ],

];

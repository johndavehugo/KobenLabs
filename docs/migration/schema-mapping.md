# Schema Mapping — Legacy to Laravel

Table-by-table mapping from `capstone-proposal/database/schema.sql` to KobenLabs migrations.

**Source of truth:** `database/schema.sql` in the legacy repo. Ignore `schema.sql.backup` — it contains two dropped tables (`screen_sessions`, `screen_participants`) and an outdated `pcs` definition.

**Target database:** MySQL `koben_lab`, InnoDB, `utf8mb4_unicode_ci`, no table prefix.

**Status: implemented.** All 18 migrations in this document have been written and verified against both MySQL 8/MariaDB and the SQLite in-memory test database. Three table names were pluralised during implementation to match their models and Laravel conventions:

| Documented below as | Actually created as | Reason |
|---|---|---|
| `attendance` | `attendances` | Matches the `Attendance` model; all other tables are plural |
| `files` | `stored_files` | Matches the `StoredFile` model, avoiding a collision with the `Illuminate\Support\Facades\File` facade |
| `user_accessibility` | `accessibility_settings` | Matches the `AccessibilitySetting` model; avoids the awkward `user_accessibilities` plural |

The headings below keep the legacy names so they line up with `legacy-system.md` and `legacy.sql`.

---

## Existing target tables

The target already has these from the stock Laravel + Filament install. Do not recreate them.

| Table | Source |
|---|---|
| `users` | Laravel default — **must be altered**, see below |
| `password_reset_tokens` | Laravel default; currently unreachable because `->passwordReset()` is not enabled |
| `sessions` | Laravel default, DB session driver |
| `cache`, `cache_locks` | Laravel default |
| `jobs`, `job_batches`, `failed_jobs` | Laravel default |
| `migrations` | Laravel default — supersedes the legacy `migrations` table |

> **Test-environment caveat:** `phpunit.xml` forces `DB_CONNECTION=sqlite` with `:memory:`. Any migration using MySQL-only DDL (`ENUM`, raw `ALTER`, partial indexes) will diverge between tests and development. Prefer portable definitions plus PHP-level validation backed by enums.

---

## Migration order

Foreign keys force a dependency order. Recommended sequence:

```
1. users            (alter existing)
2. year_sections
3. pcs
4. pc_sessions        (renamed legacy `sessions`)
5. schedules
6. class_sessions
7. attendance
8. files
9. group_chats
10. group_chat_members
11. group_chat_messages
12. group_message_reads
13. user_accessibility
14. audit_logs
```

> The legacy `messages`, `message_recipients` and `message_attachments` tables are
> **deliberately not migrated.** See [`messages` and friends](#messages-and-friends) below.

`system_config` and the legacy `migrations` table are **not migrated** — see their entries below.

---

## `users`

Legacy has 19 columns; the target's Laravel default has 8. This is a widening migration, not a create.

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | Keep `bigIncrements` |
| `username` `varchar(50)` UNIQUE | `username` `string(50)` unique | **Required.** Legacy authenticates by username, not email |
| `email` `varchar(100)` UNIQUE | `email` `string(100)` unique | Widen the Laravel default back from 255, or leave at 255 — either is fine |
| `password_hash` | `password` | Laravel names it `password`; the `hashed` cast handles hashing |
| `role` `ENUM('student','teacher','admin')` | `role` `string(20)` | Backed by `App\Enums\UserRole`. Keep as a column, not a `role_id` FK |
| `first_name` `varchar(50)` | `first_name` `string(50)` | |
| `last_name` `varchar(50)` | `last_name` `string(50)` | |
| `student_id` `varchar(20)` UNIQUE NULL | `student_id` `string(20)` unique nullable | |
| `year` `varchar(20)` NULL | — | **Replace with `year_section_id`** FK. See below |
| `section` `varchar(50)` NULL | — | **Replace with `year_section_id`** FK |
| `status` `ENUM('active','inactive','suspended')` | `status` `string(20)` default `active` | `App\Enums\UserStatus` |
| `storage_quota_mb` `int` default 500 | `storage_quota_mb` `unsignedInteger` default 500 | |
| `storage_used_mb` `decimal(10,2)` default 0 | `storage_used_mb` `decimal(10,2)` default 0 | See quota note below |
| `last_login` `timestamp` NULL | `last_login_at` `timestamp` nullable | Laravel `*_at` naming convention |
| `created_at`, `updated_at` | `created_at`, `updated_at` | Legacy both `NOT NULL DEFAULT CURRENT_TIMESTAMP`; target nullable — fine |
| — | `name` | **Drop.** Laravel default, unused. Use `first_name` + `last_name`, and a `full_name` accessor |
| — | `email_verified_at` | Keep the column; decide whether verification is required (legacy does not verify) |
| — | `remember_token` | Keep for Filament's "remember me" |

Indexes: `idx_role`, `idx_status`, `idx_year_section` on the FK.

### `year` / `section` normalisation

Legacy stores `year` and `section` as **free-text strings on the user**, duplicating the `year_sections` catalogue. `year-sections-api.php` refuses to delete a year or section while students reference it via a correlated subquery on `users.year` + `users.section` — a denormalised referential integrity check done in application code.

Replace with a nullable FK:

```php
$table->foreignId('year_section_id')->nullable()
      ->constrained('year_sections')->nullOnDelete();
```

**Backfill order matters:** populate `year_sections` from the distinct legacy `year` / `section` pairs first, then map users. Because legacy `year_sections.section` is nullable, a year with no section assigned is a distinct row (`section = NULL`).

Risk: legacy user `year`/`section` values may not match any `year_sections` row, since `User::$fillable` omitted both columns and `POST /api/users` silently dropped them. Run a reconciliation query before switching to the FK and report orphans rather than dropping them.

### Storage quota

The legacy counter is unreliable: it is incremented only on user-file upload, never for message or group-chat uploads, and never recomputed. Keep both columns for the progress-bar UI, but:

- Treat `storage_quota_mb` as authoritative and `storage_used_mb` as a **cache**.
- Recompute from `SUM(files.file_size_bytes)` via an artisan command.
- Decide the values once — legacy states them four different ways. **Recommended: 500 MB student, 2 GB teacher, 5 GB admin**, in `config/lab.php`.

---

## `year_sections`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `year` `varchar(50)` | `year` `string(50)` | |
| `section` `varchar(50)` NULL | `section` `string(50)` nullable | |
| `is_active` `boolean` default true | `is_active` `boolean` default true | Keep as-is; do not convert to soft deletes |
| `created_at`, `updated_at` | same | |

Indexes: `unique(year, section)`, `idx_year`, `idx_is_active`.

Legacy quirks to preserve as validation rules, not controller code:
- Creating a year that already exists must **not** insert a duplicate row. Direct the user to add a section instead.
- An inactive row matching the input must be **reactivated**, not duplicated.
- Deleting must be blocked while students are assigned — with the FK and `nullOnDelete` this becomes a policy check, not a correlated subquery.

`description` was dropped by a legacy migration and is not in the current schema. Do not reintroduce it.

---

## `pcs`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `pc_name` `varchar(100)` UNIQUE | `name` `string(100)` unique | Rename to `name` for Laravel/ Filament convention |
| `ip_address` `varchar(45)` UNIQUE | `ip_address` `string(45)` unique | **This is the PC identity.** See below |
| `mac_address` `varchar(17)` NULL | `mac_address` `string(17)` nullable | Decorative; "admin reference only" per the schema comment |
| `status` `ENUM('online','offline','maintenance')` | `status` `string(20)` default `offline` | `App\Enums\PcStatus`. **Never add an `available` state** |
| `last_seen` `timestamp` NULL | `last_seen_at` `timestamp` nullable | |
| `created_at`, `updated_at` | same | |

### IP vs MAC identity

The current, actual invariant is **IP-keyed identity with decorative MAC**. The `plans/mac-address-implementation.md` plan proposed making MAC the primary identifier but was **partially reverted** — `schema.sql` still has `ip_address NOT NULL UNIQUE` as the identifier and `mac_address NULL`.

Do not treat the MAC plan as current intent. See [README §7](./README.md#7-open-decisions).

Auto-detection on login (`REMOTE_ADDR` → `ip_address`, skipping `127.0.0.1` / `::1`) becomes a listener on Laravel's `Login` event, plus a scheduled `pcs:mark-stale-offline` command replacing the legacy `GET /api/pcs/cleanup-stale` endpoint. The 10-minute stale threshold and the "never auto-offline a `maintenance` PC" rule must both be preserved.

---

## `sessions` → `pc_sessions`

**Rename is mandatory.** Laravel's DB session driver already occupies the `sessions` table name in the target schema. The legacy table records user ↔ PC occupancy and is a different entity entirely.

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `user_id` | `user_id` | FK → `users`, cascade |
| `pc_id` | `pc_id` | FK → `pcs`, cascade |
| `start_time` `timestamp` | `started_at` `timestamp` | |
| `end_time` `timestamp` NULL | `ended_at` `timestamp` nullable | |
| `duration_minutes` `int` | `duration_minutes` `unsignedInteger` nullable | Derivable from `ended_at`; keep for reporting parity |
| `status` `ENUM('active','completed','terminated')` | `status` `string(20)` default `active` | `App\Enums\PcSessionStatus` |
| `terminated_by` `int` NULL | `terminated_by_id` `unsignedBigInteger` nullable | FK → `users`, `nullOnDelete` |
| `termination_reason` `text` | `termination_reason` `text` nullable | |
| `created_at` | `created_at` | |

Also rename the Eloquent model to `PcSession` to avoid colliding with anything session-related in Laravel.

---

## `schedules`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `user_id` | — | **Drop.** Redundant with `teacher_id` |
| `teacher_id` `int` NULL | `teacher_id` `unsignedBigInteger` nullable | FK → `users`, `nullOnDelete`. This is the instructor of record |
| `section_id` `int` NULL | `year_section_id` `unsignedBigInteger` nullable | FK → `year_sections`, `nullOnDelete`. Rename for clarity |
| `title` `varchar(200)` | `title` `string(200)` | Doubles as the subject name in the UI |
| `description` `text` | `description` `text` nullable | |
| `start_datetime` `datetime` | `starts_at` `datetime` | Laravel `*_at` convention |
| `end_datetime` `datetime` | `ends_at` `datetime` | |
| `purpose` `varchar(200)` | `purpose` `string(200)` nullable | |
| `status` `ENUM('scheduled','active','completed','cancelled')` | `status` `string(20)` default `scheduled` | `App\Enums\ScheduleStatus` |

Indexes: `idx_teacher_id`, `idx_year_section_id`, `idx_starts_at`, `idx_status`.

Notes:
- Legacy `config/constants.php` defines `SCHEDULE_STATUS_IN_PROGRESS = 'in_progress'`, which is **not** in the enum. The constant is unused — do not carry it forward.
- Duration bounds are 30–480 minutes (`ScheduleController`). Legacy states these as 30/240, 30/480, and 30/240 in three different places. Put one value in `config/lab.php`.
- **Conflict detection**: legacy runs an overlap query (`start < :end AND end > :start AND status != 'cancelled'`) in the controller. Move it into a validation rule or a `ScheduleObserver`.
- `day_of_week`, `start_time`, and `end_time` are view-model fields the legacy controller derives and returns but never stores. Derive them in an Eloquent accessor instead.

---

## `class_sessions`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `schedule_id` | `schedule_id` | FK → `schedules`, cascade |
| `started_by` | `started_by_id` `unsignedBigInteger` | FK → `users`, cascade |
| `started_at` | `started_at` `timestamp` | |
| `ended_at` NULL | `ended_at` `timestamp` nullable | |
| `status` `ENUM('active','completed')` | `status` `string(20)` default `active` | `App\Enums\ClassSessionStatus` |
| `created_at` | `created_at` | |

### The single-active-session invariant

Legacy enforces "only one class session may be active globally" in application code, returning 409 otherwise. MySQL has no partial unique index, so pick one:

1. **Recommended** — a dedicated command/service wrapping the check and the insert in a transaction with `lockForUpdate()` on the `class_sessions` table, plus a `Cache::lock()` to serialise across workers.
2. A generated column holding `1` when `status = 'active'` and `NULL` otherwise, with a unique index on it. Works on MySQL 5.7+/8 but diverges under the SQLite test environment.

Note the legacy rule is **global**, not per-section or per-teacher. Confirm that is still the intent — it prevents two classes running simultaneously even in different sections, which may be a bug rather than a requirement.

---

## `attendance`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `class_session_id` | `class_session_id` | FK → `class_sessions`, cascade |
| `user_id` | `user_id` | FK → `users`, cascade |
| `joined_at` `timestamp` | `joined_at` `timestamp` | |
| — | `created_at`, `updated_at` | Optional |

`UNIQUE(class_session_id, user_id)` must be preserved — it is what makes the join action idempotent. Legacy relies on `INSERT IGNORE` to swallow the duplicate; in Laravel use `firstOrCreate()`.

Legacy `Attendance::getByUserId()` joins through `class_sessions → schedules` to build a per-student history. That becomes an Eloquent relation chain: `$user->attendance()->with('classSession.schedule')`.

---

## `files`

One self-referencing table holds both files and folders.

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `user_id` | `user_id` | FK → `users`, cascade. **Ownership anchor** |
| `filename` `varchar(255)` | — | **Drop.** Redundant with `name` |
| `original_filename` `varchar(255)` | `original_name` `string(255)` | Preserve — used for the download `Content-Disposition` |
| `file_path` `varchar(500)` | `path` `string(500)` | Generated from the folder tree, never from client input |
| `file_size_bytes` `bigint` | `size` `unsignedBigInteger` | Rename; use `size` consistently across all three file tables |
| `mime_type` `varchar(100)` | `mime_type` `string(100)` nullable | Verified server-side via `finfo`, never from client input |
| `parent_folder_id` `int` NULL | `parent_id` `unsignedBigInteger` nullable | Self-FK |
| `is_folder` `boolean` default false | `is_folder` `boolean` default false | Keep — see below |
| `created_at`, `updated_at` | same | |

Indexes: `idx_user_id`, `idx_parent_id`, `idx_is_folder`.

### Folders-as-rows

Keep the single-table design. Folders are rows with `is_folder = true`, `mime_type = 'folder'`, `path = ''`, `size = 0`. Splitting into two tables would complicate the breadcrumb walk, the recursive ZIP download, and the single `FileUpload` field in Filament for no real gain.

Do **not** port the legacy `ON DELETE CASCADE` **plus** manual recursion combination. Use `ON DELETE CASCADE` on `parent_id` and let the database handle it, then run a single post-delete pass to remove physical files from storage. The legacy implementation deleted DB rows recursively but `unlink`ed only the top-level physical file, leaking every child on disk.

### Storage path generation

Legacy `createFolderPath()` writes some physical subdirectories while `file_path` stays flat, so disk and DB diverge. In the target, derive the path **exclusively** from the folder tree:

```php
$path = $folder->path() // e.g. users/6/ab/cd
    . '/' . Str::uuid7() . '.' . $extension;
```

Keep `uploads/` out of the stored path — with the `local` disk rooted at `storage/app/private`, paths are disk-relative.

---

## `messages` and friends

> **Decision: not migrated. This feature was dropped.**

Legacy's admin and student "Messages" nav items both render **group chat**. The `messages`
tables power a separate broadcast-mailbox feature that appears to have been abandoned. The
advice below was to check with the original authors before building three more tables; the
answer was no, so `messages`, `message_recipients` and `message_attachments` were never
created.

KobenLabs has **group chat only**. There are no direct messages: no mailbox, no
staff-to-student broadcast, no per-recipient read state, no message attachments. Students
reach a conversation by joining a group with its join code; staff create groups.

Concretely, this meant:

- No `messages`, `message_recipients` or `message_attachments` tables.
- No `Message` or `MessageAttachment` models, and no `MessageController`.
- Attachments live on `group_chat_messages` instead (`file_path`, `file_name`,
  `file_size`), so a file sent to a class is part of the conversation rather than a
  separate deliverable.
- Read state is per-group membership (`group_message_reads`), not per-recipient, because
  every member of a group sees the same transcript.

If this ever needs revisiting, the legacy shapes are recorded in the migration history for
this file, along with the defects that would have needed fixing first — notably
`message_attachments.original_filename`, which legacy inserts omit despite declaring
`NOT NULL`, and the missing download route.

---

## `group_chats` and friends

### `group_chats`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `name` `varchar(100)` | `name` `string(100)` | |
| `description` `text` | `description` `text` nullable | |
| `join_code` `varchar(8)` UNIQUE | `join_code` `string(8)` unique | Generate with `Str::random(8)`, **not** `str_shuffle` |
| `created_by` | `created_by_id` `unsignedBigInteger` | FK → `users`, cascade |
| `is_active` `boolean` default true | `is_active` `boolean` default true | |
| `created_at`, `updated_at` | same | |

### `group_chat_members`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `group_chat_id` | `group_chat_id` | FK → `group_chats`, cascade |
| `user_id` | `user_id` | FK → `users`, cascade |
| `role` `ENUM('admin','member')` | `role` `string(20)` default `member` | `App\Enums\GroupRole`. **Group-scoped, unrelated to `UserRole`** |
| `joined_at` | `created_at` | |

`UNIQUE(group_chat_id, user_id)` must be preserved. Model as a pivot — `GroupChat::members()` via `belongsToMany` with a `role` pivot column.

### `group_chat_messages`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `group_chat_id` | `group_chat_id` | FK → `group_chats`, cascade |
| `sender_id` **NULL** | `sender_id` `unsignedBigInteger` nullable | **Must stay nullable** — system messages have no sender |
| `message` `text` | `body` `text` | |
| `message_type` `ENUM('text','file','image','system')` | `type` `string(20)` default `text` | `App\Enums\GroupMessageType` |
| `file_path` NULL | `file_path` `string(500)` nullable | |
| `file_name` NULL | `file_name` `string(255)` nullable | |
| `file_size` `int` NULL | `file_size` `unsignedInteger` nullable | Note: legacy uses `int` here but `bigint` elsewhere — widen to `unsignedBigInteger` for consistency |
| `reply_to_id` NULL | `reply_to_id` `unsignedBigInteger` nullable | Self-FK, `SET NULL` on delete. Column exists; the legacy UI never uses it |
| `created_at`, `updated_at` | same | |

Max message length is 2000 characters (legacy). Put it in `config/lab.php`.

### `group_message_reads`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `message_id` | `message_id` | FK → `group_chat_messages`, cascade |
| `user_id` | `user_id` | FK → `users`, cascade |
| `read_at` | `read_at` `timestamp` | |

`UNIQUE(message_id, user_id)` must be preserved.

---

## `user_accessibility`

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `user_id` | `user_id` | FK → `users`, cascade |
| `text_size` `ENUM('normal','large','extra-large')` | `text_size` `string(20)` default `normal` | `App\Enums\TextSize` |
| `high_contrast` `boolean` default false | `high_contrast` `boolean` default false | |
| `text_to_speech` `boolean` default false | `text_to_speech` `boolean` default false | |
| `created_at`, `updated_at` | same | |

`UNIQUE(user_id)` must be preserved. Model as a one-to-one `User::accessibilitySettings()`.

**Legacy has no ownership check on this table** — `GET|PUT /api/users/{id}/accessibility` lets any authenticated user read or write any other user's preferences. In the target, enforce `$user->id === auth()->id()` in a policy or scope it to `auth()->user()->accessibilitySettings`.

Build decision: see [README §7](./README.md#7-open-decisions). If accessibility is deferred, do not migrate the table — the whole feature is dead code today.

---

## `audit_logs` — adopt, do not drop

| Legacy column | Target | Notes |
|---|---|---|
| `id` | `id` | |
| `user_id` NULL | `user_id` `unsignedBigInteger` nullable | FK → `users`, `nullOnDelete`. Nullable so the row survives user deletion |
| `action` `varchar(100)` | `action` `string(100)` | |
| `entity_type` `varchar(50)` | `auditable_type` `string(50)` nullable | Morph class name |
| `entity_id` `int` | `auditable_id` `unsignedBigInteger` nullable | |
| `details` `text` | `properties` `json` nullable | JSON is better than text for queryability |
| `ip_address` `varchar(45)` | `ip_address` `string(45)` nullable | |
| `user_agent` `varchar(255)` | `user_agent` `string(255)` nullable | Widen to `text` — user agents exceed 255 |
| `created_at` | `created_at` | |

**This is the one legacy artifact worth carrying forward.** The table exists with the right shape but is **never written to** — `Logger::audit()` writes JSON lines to `logs/audit.log` instead. Wire it up with an `AuditObserver` on the models that matter, dispatching through a queued job so the write never blocks a request.

Alternative: adopt `spatie/laravel-activitylog` and skip the custom observer. That is defensible; the argument for the custom table is full control over the schema and no new dependency.

---

## `system_config` — retire

| Legacy column | Disposition |
|---|---|
| `config_key`, `config_value`, `description`, `updated_at`, `updated_by` | **Do not migrate** |

The table is seeded with six keys (`session_timeout_minutes`, `max_file_upload_mb`, `min_reservation_minutes`, `max_reservation_minutes`, `student_storage_quota_mb`, `teacher_storage_quota_mb`) and **never read by any application code**. Every effective value lives in `config/constants.php`, re-hardcoded in three or four places each. Worse, the seeded values **contradict** the constants.

Migrate these to `config/lab.php`. If runtime-editable settings are genuinely needed later, add a `settings` table then, driven from a Filament Page — not a dead table replicated from the legacy schema.

---

## `migrations` — retire

Laravel's own `migrations` table supersedes it. Legacy `utils/migrate.php`, which maintained it, is fatally broken (it calls a `Database` class that only exists inside `utils/health-check.php`) and there is no working runner to port.

---

## Tables to drop outright

| Legacy table | Reason |
|---|---|
| `screen_sessions` | Removed from the live schema; only present in the stale `schema.sql.backup`. `utils/health-check.php` still asserts it exists |
| `screen_participants` | Same |

Do not create these. If the monitoring feature is rebuilt from scratch, design it properly rather than resurrecting the old shape.

---

## Summary of renames

Table renames:

| Legacy table | Target table | Reason |
|---|---|---|
| `sessions` | `pc_sessions` | **Collision** with Laravel's DB session table |
| `attendance` | `attendances` | Match the model; all other tables are plural |
| `files` | `stored_files` | Match the model; avoid the `File` facade collision |
| `user_accessibility` | `accessibility_settings` | Match the model |

Column renames:

| Legacy | Target | Reason |
|---|---|---|
| `files.filename` | `stored_files.name` | Laravel convention |
| `files.original_filename` | `stored_files.original_name` | Consistency |
| `files.file_size_bytes` | `stored_files.size` | Consistency across file tables |
| `files.parent_folder_id` | `stored_files.parent_id` | Laravel convention |
| `pcs.pc_name` | `pcs.name` | Laravel convention |
| `pcs.last_seen` | `pcs.last_seen_at` | Laravel convention |
| `schedules.section_id` | `schedules.year_section_id` | Clarity |
| `schedules.start_datetime` / `end_datetime` | `starts_at` / `ends_at` | Laravel convention |
| `schedules.user_id` | *dropped* | Duplicated `teacher_id` |
| `users.year` + `users.section` | `users.year_section_id` | Normalisation |
| `users.last_login` | `users.last_login_at` | Laravel convention |
| `pc_sessions.start_time` / `end_time` | `started_at` / `ended_at` | Laravel convention |
| `class_sessions.started_by` | `class_sessions.started_by_id` | Laravel convention |
| `group_chat_messages.message` | `group_chat_messages.body` | Consistency |
| `audit_logs.entity_type` / `entity_id` | `auditable_type` / `auditable_id` | Morph naming convention |
| `audit_logs.details` | `audit_logs.properties` | JSON rather than text, so it is queryable |

---

## Enums to create

| Enum | Cases |
|---|---|
| `UserRole` | `Student`, `Teacher`, `Admin` |
| `UserStatus` | `Active`, `Inactive`, `Suspended` |
| `PcStatus` | `Online`, `Offline`, `Maintenance` |
| `PcSessionStatus` | `Active`, `Completed`, `Terminated` |
| `ScheduleStatus` | `Scheduled`, `Active`, `Completed`, `Cancelled` |
| `ClassSessionStatus` | `Active`, `Completed` |
| `GroupMessageType` | `Text`, `File`, `Image`, `System` |
| `GroupRole` | `Admin`, `Member` |
| `TextSize` | `Normal`, `Large`, `ExtraLarge` |

All string-backed, applied via a `casts()` method.

`UserRole` needs a helper for the repeated legacy check:

```php
enum UserRole: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Admin = 'admin';

    public function canManageLab(): bool
    {
        return $this === self::Teacher || $this === self::Admin;
    }
}
```

`PcStatus` needs the auto-offline rule expressed once:

```php
public function isAutoOfflinable(): bool
{
    return $this === self::Online; // never touch Maintenance
}
```
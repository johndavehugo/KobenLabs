# Computer Lab Management System — Migration Guide

**Target:** KobenLabs (`C:\Users\JD\Herd\KobenLabs`)
**Source:** `capstone-proposal` (`C:\Users\JD\Herd\capstone-proposal`) — registered as the `capstone` reference in `opencode.json`

Porting the legacy computer lab management system from framework-less PHP/PDO to Laravel 13 + Filament 5.

---

## Documents in this set

| Document | Purpose |
|---|---|
| [README.md](./README.md) (this file) | Scope, target architecture, concept mapping, phased plan, risks, open decisions |
| [legacy-system.md](./legacy-system.md) | As-built inventory of the legacy system: schema, features, endpoints, roles, realtime, tests, dead code |
| [schema-mapping.md](./schema-mapping.md) | Table-by-table legacy → Laravel migration mapping with rationale |

---

## 1. Scope

### In scope
All 8 live feature areas of the legacy app: PC inventory, class sessions, attendance, schedules, year/sections, user management, personal file drive, group chat, and admin dashboard. 19 database tables. 61 HTTP endpoints. 4 WebSocket event types.

### Out of scope (or requiring an explicit product decision)
- **Broadcast** — legacy is a stub ("under development") with no backend. Replaced with a self-hosted Reverb server: four authorized private channels, browser listeners wired on both panels.
- **Reports** — referenced throughout the legacy docs and tests, **does not exist in code**. No resource, no route, no view.
- **Student attendance summaries** — a 239-line view exists but is unreachable and its endpoint was never routed.
- **Screen monitoring** — covert screen streaming of student PCs, working in legacy. **Rebuilt fresh as Live Screens**, not ported: the lab machines are institution-owned, so the blocker was engineering rather than permission, and legacy's transport and authorisation were rebuilt rather than copied. See [§7](#7-decisions).
- **Accessibility panel** — specified in `USER_MANUAL.md` to WCAG 2.1 AA, but the implementing JS/CSS is entirely dead code. The table (`accessibility_settings`) is populated by an API with no auth check.

### Target state
The current app is a **stock Laravel 13 + Filament 5 skeleton**. There is no domain code, no domain schema, and zero Filament resources. Every table, model, policy, enum, and resource below is greenfield. Do not assume any of this work exists.

---

## 2. Target architecture

### Verified stack

| Component | Version |
|---|---|
| PHP | 8.4.25 |
| Laravel | 13.34.0 |
| Filament | 5.9.0 (`schemas`, `forms`, `tables`, `infolists`, `actions`, `widgets`, `notifications`, `query-builder`) |
| Livewire | 4.4.7 |
| Pest | 5.3.0 |
| Database | MySQL / InnoDB / `utf8mb4_unicode_ci`, database `koben_lab` |
| Tailwind CSS | 4.3.3 (CSS-first config, no `tailwind.config.js`) |
| Vite | 8.3.2 |

### Two panels, replacing two portals

The legacy app has two hard-separated portals with disjoint navigation (`views/admin/index.html` and `views/user/index.html`), gated on `role ∈ {teacher, admin}` vs `role === 'student'`. Map this directly onto two Filament panels rather than role-gating one panel — it keeps the separation enforceable and gives each audience an appropriate default landing page.

| Panel | Path | Audience | Registration |
|---|---|---|---|
| `admin` (exists) | `/admin` | teacher, admin | `->strictAuthorization()` |
| `student` (new) | `/student` | student | `->strictAuthorization()` |

```php
// app/Providers/Filament/StudentPanelProvider.php
public function panel(Panel $panel): Panel
{
    return $panel
        ->id('student')
        ->path('student')
        ->login()
        ->strictAuthorization()
        ->colors(['primary' => Color::Blue])
        ->discoverResources(in: app_path('Filament/Student/Resources'), for: 'App\\Filament\\Student\\Resources')
        ->pages([Dashboard::class])
        ->widgets([AccountWidget::class]);
}
```

### Authorization model

Legacy has **no permission matrix** — three roles, checked ad-hoc in 6 controller methods, with `RoleMiddleware` written but never registered. Do not port that.

| Legacy mechanism | Target |
|---|---|
| `ENUM('student','teacher','admin')` on `users.role` | `App\Enums\UserRole` (string-backed enum) on the same column, plus a policy per model |
| `requireTeacherRole()` in controllers | Policy methods |
| `RoleMiddleware` (dead) | `Gate` + policies; Filament `->strictAuthorization()` |
| `adminOnly: true` nav flag (JS only) | Policy-gated navigation + `FilamentUser::canAccessPanel()` |
| — | Consider `spatie/laravel-permission` if per-action grants are ever needed. Not required for the 3-role model. |

**Status: implemented.** Fourteen policies in `app/Policies/`, covering every model, pinned by a 32-test matrix in `tests/Feature/PolicyMatrixTest.php`. `User` implements `FilamentUser` and the admin panel has `->strictAuthorization()`.

There is **no blanket administrator bypass.** Each policy states its own rules explicitly, because several abilities need to deny even an admin:

| Restricted even for admins | Reason |
|---|---|
| Deleting, suspending or password-resetting **their own** account | Locking yourself out of the last admin account is unrecoverable |
| Editing or deleting an **attendance** record | A mark that can be changed after the fact is not evidence |
| Editing a **message** | Messages are immutable once sent |
| Writing to **another user's** `AccessibilitySetting` | An administrator resizing a student's text is disabling their accessibility |
| Any `AuditLog` create / update / delete | An audit log that can be truncated is not an audit log |
| Reading a **private message** they are not party to | A message between two teachers is not lab business |
| Reorganising **another user's** file drive | Read and delete are granted for support; moving someone's files is not |

`UserPolicy` is the only policy with a `before()`, and it skips exactly the three self-service abilities above.

```php
// app/Models/User.php
class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->canAuthenticate()) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->role->canManageLab(),
            'student' => $this->role === UserRole::Student,
            default => false,
        };
    }
}
```

> `FilamentUser` is **mandatory in production**. Filament refuses panel access to any user model that does not implement the contract when `APP_ENV !== 'local'`.

### Realtime

Legacy runs a second process (Workerman on port 8080) bridged to PHP by a **single-slot JSON file** (`tmp/ws_broadcast.json`, polled every 100 ms), with **zero authentication** — any socket client can register as `teacher` and receive every broadcast.

Replace with Laravel broadcasting end to end:

| Concern | Legacy | Target |
|---|---|---|
| Transport | Raw WebSocket via Workerman, port 8080 | `laravel/reverb` (or Pusher) — `install:broadcasting` |
| Bridge | Single-slot file mailbox (drops concurrent events) | Native `ShouldBroadcast` events |
| Auth | None | Private channels + Echo `auth` endpoint |
| Client | `public/js/core/ws-client.js`, host hardcoded | Laravel Echo + Reverb JS |
| Config | Hardcoded in 3 files | `.env` (`BROADCAST_CONNECTION`, `REVERB_*`) |

Events to define as `ShouldBroadcast`: `PcStatusUpdated`, `ClassSessionStarted`, `ClassSessionEnded`, `StudentJoinedClass`, `GroupChatMessageSent`.

The current app has **no** `config/broadcasting.php` and `BROADCAST_CONNECTION=log`, so nothing is delivered today. This is Phase 5 work, not a prerequisite for the rest.

### File storage

Legacy writes to three directories with three different validation policies and no type restriction on two of them. Consolidate on Laravel's filesystem with one `FileService`:

| Zone | Legacy path | Target disk / path |
|---|---|---|
| User drive | `uploads/users/{userId}/{md5_8}_{name}.{ext}` | `local` disk, `users/{userId}/{uuid7}.{ext}` |
| Group chat files | `uploads/group-chats/{groupId}/…` | `local` disk, `group-chats/{groupId}/…` |

Direct-message attachments are not listed: that feature was dropped, so files belong to a
group chat or a user drive and nothing else.

Use `Storage::disk('local')` throughout. Add one `private` disk if the group-chat download path needs a signed URL. Enforce a **single** MIME allowlist in `config/lab.php` — legacy has three disagreeing limits (50 MB / 50 MB / 10 MB).

---

## 3. Concept mapping

### Feature → target surface

| Legacy feature | Legacy impl | Target |
|---|---|---|
| Landing / portal picker | `views/landing.html` | Redirect to `/admin` or `/student`; Filament login pages |
| Login / logout | `AuthController` + `services/AuthService.php` | Filament auth + `->passwordReset()` + `->profile()` |
| Admin dashboard | `admin/views/dashboard.js` | Filament `Dashboard` + `StatsOverviewWidget`s (PC online/offline/maintenance/total) |
| Student dashboard | `user/views/dashboard.js` | Student-panel `Dashboard` + storage/unread widgets |
| PC inventory | `PCController` + `models/PC.php` + `admin/views/pcs.js` | `PcResource` + `PcStatusUpdated` broadcast — **done** |
| PC auto-detect on login | `AuthController` IP match → `pcs.status` | `PcPresenceService` on the `Login`/`Logout` events + `pcs:mark-stale-offline` — **done** |
| Class sessions | `ClassSessionController` + `admin/views/class-sessions/*` | `ClassSessionResource` (start/end) — **done** |
| Attendance | `models/Attendance.php` | `AttendancesRelationManager` (read-only roster) on `ClassSessionResource` — **done** |
| **Join class** | `user/views/class-sessions.js` "Join Class" → `POST /api/class-sessions/{id}/join` | `JoinClass` page on the student panel — **done** |
| Schedules | `ScheduleController` + `admin/views/schedule.js` | `ScheduleResource` + `ScheduleObserver` — **done** |
| Year / section | `models/YearSection.php` + `year-sections-api.php` | `YearSectionResource` (drop the unauthenticated script) |
| User management | `UserController` + `users-api.php` | `UserResource` (drop the role-less script) |
| Personal file drive | `FileController` + `user/views/files/*` (11 modules) | `MyDrive` page (student) + read-only `StoredFileResource` (staff) — **done** |
| Group chat | `GroupChatController` + `admin|user/views/messages.js` | `GroupChatResource` + `ChatRoom` on both panels — **done** |
| Direct messages | `MessageController` (built, but no view ever linked to it) | **Dropped** — group chat only, see [schema-mapping](schema-mapping.md#messages-and-friends) |
| Attachment downloads | None for messages; unauthenticated path parameter for group chat | Two policy-gated routes — **done** |
| Remote monitoring | `MonitoringController` + Python agent + WS frames | **Rebuilt fresh as Live Screens** — no media relay, per-machine token, policy-scoped frames — see [§7](#7-decisions) |
| Broadcast | Stub | Self-hosted Reverb, three authorized private channels — **done** |
| Accessibility | Dead JS/CSS + live-but-unauthenticated API | Drop, or build as an `AccessibilitySetting` relation manager |

### Data → target

| Legacy construct | Target |
|---|---|
| `models/BaseModel.php` (hand-rolled CRUD over PDO) | Eloquent |
| `utils/Router.php` (first-match-wins regex router) | Laravel router + Form Requests |
| `utils/Response.php` (`echo` + `exit`) | `JsonResponse` / Filament actions |
| `utils/Validator.php` (static methods) | Form Requests + `#[Validate]` attributes |
| `utils/CSRF.php` | Laravel's built-in CSRF (`PreventRequestForgery`) — **already active** |
| `middleware/AuthMiddleware.php` | `auth` middleware + `->authMiddleware()` on the panel |
| `services/WebSocketService.php` (file mailbox) | `ShouldBroadcast` events |
| `config/constants.php` (~30 inert constants) | `config/lab.php` |
| `system_config` table (dead) | Retire — use `config/` + cache |
| `audit_logs` table (dead) | **Adopt and wire up** — see [§6](#6-security-debt-to-repay) |
| Jest + Playwright suites | Pest (unit/feature) + Laravel Dusk or Playwright for e2e |

Full table-by-table detail: [schema-mapping.md](./schema-mapping.md).

---

## 4. Phased plan

Each phase is independently shippable. Phases 0–3 need no realtime; Phase 4 needs no broadcasting.

### Progress

| Phase | Status |
|---|---|
| 0 — Prerequisites | **Done.** Boost guidelines installed, `CLAUDE.md` re-synced, `.env.example` corrected |
| 1 — Foundation | **Schema, models, policies and the first two resources done.** 18 migrations applied to `koben_lab`; 9 enums, 14 models, 13 factories, 14 policies; `UserResource` and `YearSectionResource` |
| 2 — Labs and academics | **Done.** PC presence end to end (service, listeners, sweep, `PcResource`); `ScheduleObserver` + `ScheduleBookingRules`; `ClassSessionService`, `ScheduleResource`, `ClassSessionResource`, read-only attendance roster |
| 3 — Storage and communication | **Done.** File drive (`FileService`, one quota path, `MyDrive`, read-only `StoredFileResource`), group chat (`GroupChatResource` + `ChatRoom` on both panels), and both policy-gated download routes. Direct messages were dropped — group chat only |
| 4 — Dashboards and parity | Not started |
| 5 — Realtime | Not started |

### Phase 0 — Prerequisites (do first)

The target app has two setup gaps that will corrupt any migration work started before they're fixed.

- [ ] Run `php artisan boost:install` — `AGENTS.md` still contains only the pre-install bootstrap placeholders. Boost will replace them with project-specific guidelines, and **will rewrite `AGENTS.md`**.
- [ ] Fix `.env.example` — it declares `DB_CONNECTION=sqlite` and comments out every credential, while the live app runs MySQL `koben_lab`. As written it will mislead every setup and CI step.
- [ ] Enable Filament auth features that legacy implicitly had: `->passwordReset()`, `->profile()`, `->registration()` (off — accounts are created by staff).
- [x] Record the decisions in [§7](#7-decisions). Screens, broadcast, messages, quota, PC identity, session timeout and accessibility settled; reports and Echo listeners remain.

### Phase 1 — Foundation: identity, roles, academic structure

- [ ] Enums: `UserRole`, `UserStatus`, `PcStatus`, `ScheduleStatus`, `ClassSessionStatus`, `MessageType`. — **done**
- [ ] Migrations + models for `users` (altered), `year_sections`. — **done**, and applied to `koben_lab`
- [ ] `UserRole` enum, `User` relations, factories for every model. — **done**
- [ ] `UserPolicy` with a `before()` admin bypass; policies for `YearSection`, `Schedule`, `Pc`, `ClassSession`, `Attendance`, `StoredFile`, `GroupChat`. — **done** (14 policies; no blanket bypass — see [§2](#authorization-model))
- [ ] `UserResource` (admin panel), `YearSectionResource`. — **done**
- [ ] `->strictAuthorization()` on both panels. — **done** on the admin panel; the student panel does not exist yet
- [ ] Pest: policy matrix tests (the legacy app has no equivalent — see the capability table in [legacy-system.md](./legacy-system.md#6-role--capability-matrix-as-actually-enforced)). — **done**

### Phase 2 — Labs and academics

- [ ] `pcs` migration + model + `PcResource` + `PcStatus` enum. — **done**
- [ ] PC auto-detect: identify by IP on login, set `online`, stamp `last_seen`. — **done** via `PcPresenceService` + login/logout listeners
- [ ] Scheduled command `pcs:mark-stale-offline` — replaces the legacy `GET /api/pcs/cleanup-stale` endpoint that the UI called on every page load. 10-minute threshold; never auto-offline a `maintenance` PC. — **done**, on a 5-minute schedule
- [ ] `schedules` migration + model + `ScheduleResource` with conflict detection (a `ScheduleObserver` or a validation rule on `start_datetime`/`end_datetime` overlap). — **done**; rules live in `ScheduleBookingRules`, enforced by `ScheduleObserver` for every write path and re-checked by the pages so failures land inline
- [ ] `class_sessions` + `attendances` migrations and models. — **done**; `ClassSessionObserver` holds the single-active-session rule behind a cache lock
- [ ] `ClassSessionResource`, read-only `AttendanceResource`, custom `MonitorClassSession` page (start / end / live roster). — **done** as `ClassSessionResource` + `AttendancesRelationManager`. The custom `MonitorClassSession` page is superseded by the read-only View page plus the list-page End action

### Phase 3 — Storage and communication

- [x] `stored_files` migration (self-referencing tree) + `StoredFile` model + `FileService`.
- [x] Storage quota enforcement in one place (legacy has three disagreeing quota sources — see [schema-mapping.md](./schema-mapping.md#users)).
- [x] `StoredFileResource` (read-only, staff support) + `MyDrive` page (breadcrumb, table listing, upload, new folder, quota readout).
- [x] `group_chats` + members + messages + reads migrations and models.
- [x] `GroupChatResource` (admin, with a members relation manager) + `ChatRoom` page on both panels.
- [x] Group chat file uploads and the policy-gated download endpoint legacy never had.
- [x] **Decision taken:** drop the direct-message mailbox. Legacy shipped the tables but no
      view ever linked to them, so keeping them would have meant building three tables and
      two pages of new surface for a feature nobody had used. Group chat only.

#### Communication: what was built and what it closed

| Legacy behaviour | Now |
| --- | --- |
| `download-group-file.php` took a **path** and performed no membership check, so any signed-in user could fetch any group's attachments by guessing | `DownloadGroupChatFile` resolves a `GroupChatMessage` and asks `GroupChatMessagePolicy::download`, which defers to `GroupChatPolicy::view` |
| Group chat attachments were served from an unauthenticated path parameter | `DownloadGroupChatFile` resolves a `GroupChatMessage` and asks `GroupChatMessagePolicy::download`, which defers to `GroupChatPolicy::view`. One route, two policy gates — the attachment is not a way around the message's own visibility |
| `Content-Disposition` built from an unsanitised filename | `Storage::download()` sets the header; a test asserts a CRLF filename cannot inject a header |
| `str_shuffle()` for join codes, which is not cryptographically strong | `GroupChat::generateJoinCode()` uses `Str::random()` and retries on collision |
| Membership check duplicated as an inline `foreach` in five controller methods | `GroupChatPolicy` / `GroupChatMessagePolicy`; the transcript logic lives once in `InteractsWithChatRoom` and each panel gets a thin `Page` |

Two Filament-specific traps found while building this, both worth remembering:

- **`mount()` runs once per page load.** Marking a message read in `mount()` alone meant a message opened *from the list* stayed
  unread until a reload, so the unread badge never cleared. Handled with `updatedMessageId()` plus an explicit call in the row
  action, because assigning a public property server-side does not fire the updated hook.
- **The admin panel discovers pages from `app/Filament/Pages`, not `app/Filament/Admin/Pages`.** A page placed in the
  latter registers no route at all and fails silently.
- **`protected static ?string $slug` is the page property**; `$routeSlug` is not read, so a wrong name fails silently and the
  URL falls back to the class name.

Read state lives in `group_message_reads` and is what the sidebar's unread badge counts. It is written when a group is opened,
not by a button: a badge that only clears when you find and press a control stays wrong all lesson. Two consequences worth
recording:

- **The author's own messages are excluded.** Somebody has by definition read what they wrote, so counting it would leave a
  permanent badge on every group a user has ever posted in.
- **One insert, not a loop.** `GroupChat::markAllReadBy()` collects the unread ids and inserts them in a single statement,
  using `insertOrIgnore` rather than an upsert. `group_message_reads` is unique on `(message_id, user_id)`, so two tabs open
  on the same group both decide the same messages are unread — an upsert would fail the second request outright.

#### What the file drive closed

`FileService` is the single gate every upload path goes through. Each item below was a
real legacy defect, and each has a test named after it.

| Legacy behaviour | Now |
| --- | --- |
| `ALLOWED_EXTENSIONS` and a 30-entry MIME list declared in `FileController` and never invoked | `assertAllowed()` checks the extension, the sniffed bytes, and the size |
| Group chat validated `$_FILES['type']`, which the client controls | `assertAllowed()` reads the file with `finfo`. `$file->getMimeType()` is deliberately **not** used: under `Illuminate\Http\Testing\File` it resolves from the *name*, so the check would validate the fixture rather than the payload |
| Group chat files trusted the client's filename | Same allowlist as the drive |
| Quota enforced only on user file uploads, so group chat uploads were uncounted | `assertWithinQuota()` on every write, recomputed from `SUM(size)` rather than trusting the drifting `storage_used_mb` column |
| `FileService::deleteTree()` recursed in the database but unlinked only the top-level blob | Blobs for the node **and** every descendant are removed. `StoredFile::deleteTree()` had the same gap — it swept `descendantIds()`, which excludes the node itself, so deleting a single file leaked its blob |
| `Content-Disposition` built from the unsanitised `original_filename` | `Storage::download()` sets the header |
| Client supplied `folder_path`, which then disagreed with the written path | Path derived from the folder tree; a parent folder belonging to another account is refused |
| Group chat uploads stored no original filename, so downloads had none to offer | `original_name` supplied and the download header set by `Storage::download()` |

Two Filament-specific hazards are worth recording, because neither is visible from the
legacy code:

- **Filament does not add `RestrictsFileUploadsToSchemaComponents` to Pages or Resources**,
  only to its own authentication pages. Without it, Livewire's `_startUpload` and
  `_finishUpload` accept uploads to *any* property path on the page. The trait is now on
  both student pages, with tests in `tests/Feature/UploadRpcRestrictionTest.php`.
- **An upload field inside an action's modal schema is not a discovered schema**, so that
  trait would refuse a real browser upload with a 403 while a test that hands the action
  a file directly still passed. The field therefore lives in `MyDrive`'s own page schema
  and the `Upload` action commits it. A test asserts the field stays addressable.

### Phase 4 — Dashboards and parity

- [x] Admin dashboard widgets (PC status doughnut, active session, occupancy).
- [x] Student dashboard widgets (storage %, unread count, recent activity).
- [x] Attendance summary per subject for students.
- [ ] Port the verified legacy integration-test surface to Pest. Legacy's suites confirm only auth, users, PCs, schedules, messages, and files — see [legacy-system.md](./legacy-system.md#7-testing) for what is genuinely covered versus what merely appears to be.

There is no separate parity checklist document. Parity is tracked by the test suite, which is the more reliable record: it cannot go stale the way a hand-maintained checklist did. `php artisan test --compact` is the authority on what is covered.

### Phase 5 — Realtime

- [x] Self-hosted Reverb (`laravel/reverb`), `config/broadcasting.php`, active `broadcasting.echo` block in `config/filament.php`.
- [x] Broadcast events: `PcStatusUpdated`, `ClassSessionStarted`, `ClassSessionEnded`, `StudentJoinedClass`, `GroupChatMessageSent`.
- [x] Private channels with `routes/channels.php` authorization — **the legacy socket has no auth at all; do not port that**.
- [x] Echo listeners on both panels, registered at `PanelsRenderHook::SCRIPTS_AFTER`. Reverb is self-hosted so student attendance and PC activity stay on school infrastructure rather than a hosted service with a per-connection quota.
- [x] Screen agent replaced by the Lab Client in `clients/lab-client/` — built fresh, not ported. The server side (enrolment, per-machine token guard, frame endpoint, `viewScreen` policy, Live Screens grid) is complete and tested.

---

## 5. Data migration

There is no legacy data in production — `capstone-proposal` is a capstone project and the target `koben_lab` database contains one hand-edited user row. The only live data that matters is **seed/reference data**: PC inventory, academic years and sections, and user accounts.

Approach: write idempotent seeders and factories rather than a legacy-data importer.

- [ ] `PcSeeder` — from the legacy `pcs` rows. **PCs are keyed by IP address**; `mac_address` is nullable and decorative. The `plans/mac-address-implementation.md` plan to make MAC the identity was partially reverted — do not treat it as current intent.
- [ ] `YearSectionSeeder` — from legacy `year_sections` (`is_active = 1`).
- [ ] `UserSeeder` — one admin with `role = 'admin'`. **Legacy's seeded admin has `role = 'teacher'`**, which means it cannot see its own Users/PCs nav items. Do not replicate this.
- [ ] A command to recompute `storage_used_mb` from the `stored_files` table, since the legacy counter is only incremented on user-file upload and never for message or group-chat files.

---

## 6. Security debt to repay

Do **not** port these. The legacy app has them in production code today.

| # | Legacy issue | Target |
|---|---|---|
| 1 | `CSRFMiddleware` written and loaded, **never registered** — app-wide CSRF is unprotected | Laravel's `PreventRequestForgery` (already on the panel) |
| 2 | `RoleMiddleware` written, loaded, **never instantiated** — no route-level authz | Policies + `strictAuthorization()` |
| 3 | `PCController::store/update/destroy` have **zero** role checks — any authenticated student can edit the PC inventory | `PcPolicy` |
| 4 | `year-sections-api.php` has **no authentication** and sends `Access-Control-Allow-Origin: *` | Delete the script; `YearSectionResource` |
| 5 | `users-api.php` checks only `$_SESSION['user_id']` — any student can create/delete users, including promoting themselves to admin | Delete the script; `UserResource` + `UserPolicy` |
| 6 | `download-group-file.php` — session check only, no group-membership check; takes a raw `?path=` | `GroupChatPolicy` + `Storage` path generation |
| 7 | WebSocket has **zero** authentication — register as `teacher`, receive every broadcast | Private broadcast channels |
| 8 | CORS reflects `Origin` with `Allow-Credentials: true` | Delete; Laravel handles CORS |
| 9 | `index.php` hardcodes `display_errors=1` regardless of environment | `APP_DEBUG` |
| 10 | No `session_regenerate_id()` on login | Laravel's session guard |
| 11 | Login rate limit stored in `$_SESSION`, per-session, trivially bypassable | `RateLimiter` (cache-backed) |
| 12 | `User::$fillable` omits `year`/`section`, silently dropping them on create | Explicit `$fillable` / request validation |
| 13 | File upload: no MIME restriction on two of three zones; group-chat allowlist validates the **client-supplied** `$_FILES['type']` | `Rule::mimes()` + server-side `finfo` check |
| 14 | Quota enforcement only on user files; message and group-chat uploads uncounted | One `FileService` quota path |
| 15 | `audit_logs` table exists but is never written; `Logger::audit()` writes JSON lines instead | Adopt the table; see below |
| 16 | `debug.php` is web-reachable and executes `ALTER TABLE pcs ADD mac_address` | Do not port |
| 17 | Hardcoded credentials `admin` / `Admin123!` in `USER_MANUAL.md` and `INSTALL.md` | No default accounts in seeders |

On #15: the legacy `audit_logs` table has the right shape (user, action, entity type/id, details, IP, user agent, timestamp) and is simply unwired. Adopt it as the real audit trail — an `AuditObserver` on the models that matter, writing through a queued job. This is the one place where carrying a legacy artifact forward is clearly better than starting fresh.

**Now wired.** `AuditableObserver` plus the queued `RecordAuditEntry` job, registered from `config('lab.audit.models')`.

Two decisions worth stating, because both trade completeness for usefulness:

- **Only a configured list of models, not everything.** Attendance rows, PC sessions and chat messages would bury the entries that matter — a promoted user, a deleted drive, a changed schedule.
- **Changes, not reads.** Polling every read would make the trail useless.

Passwords are redacted before they reach the log. An audit trail is readable by staff, so a password hash in it is a credential store with a helpful index. `AuditTrailTest` covers the redaction, the from/to pairs, the self-exclusion and the unauthenticated case (seeding writes a row with a null user rather than dropping it).

**Trade-off:** the write is queued, so an audit failure never rolls back the staff action that caused it — and if the queue is down the entry is lost. Deliberate.

On #7: replaced with a self-hosted **Laravel Reverb** server. `routes/channels.php` declares four private channels, each asking a policy:

| Channel | Who may subscribe | Why |
|---|---|---|
| `pcs` | staff only | PC inventory and occupancy are not a student's business |
| `class-sessions` | any authenticated user | The student panel needs to be told when a class starts, which is what makes the Join Class button appear |
| `groups.{groupId}` | group members and staff | The group id is in the channel name, so `GroupChatPolicy::view` decides — a client cannot subscribe to a group it is not in |
| `monitoring` | staff only | Announces that a PC has a newer frame. Carries **no image data** — see the Live Screens decision above |

A broadcast never carries rendered content here: `ScreenFrameAvailable` sends a
`pc_id` and the browser then fetches that frame over HTTP through
`PcPolicy::viewScreen`. That keeps the websocket a signalling channel rather than a
media server, and it means "may subscribe" is never confused with "may see" — which
is precisely the substitution legacy's relay made.

Note that `class-sessions` carries `StudentJoinedClass`, so a student learns that *other* students joined a running class. That roster information was already visible to every signed-in user in the legacy app through an unauthenticated endpoint; the roster rows themselves still come from `AttendancePolicy`, which only lets the session's own teacher read them. Narrowing this channel per session is the obvious next tightening.

`BroadcastChannelAuthTest` pins all of it, including the deny-by-default case: a channel nobody declared is not subscribable.

---

## 7. Decisions

### Settled

1. **Screen monitoring — BUILT FRESH as Live Screens.** Originally dropped, then reconsidered: these are the lab's own machines, owned by the institution, so there is no third-party device being watched and no consent question of the kind that made the legacy feature a problem. What is still owed is engineering, not permission — and the legacy implementation had serious faults, so it was rebuilt rather than ported. Full reasoning below.

   ### Screen monitoring: Live Screens

   **Why not a port.** The legacy design streamed 20 fps at 1280 px over a WebSocket relay that:

   - accepted `agent_register` from **anything** that connected, with no credential,
     trusting a self-reported IP — so any host on the lab network could inject a fake
     machine's screen into a teacher's grid;
   - let a browser **self-assert `{"role": "teacher"}`** from `localStorage`, so a
     student who edited one key received every screen in the lab. Its HTTP roster
     endpoint *was* role-checked, which made the socket a complete bypass of the only
     control present;
   - **fanned every frame to every connected teacher**, including teachers with no
     class. The "only my class" filtering was a client-side check over a 10-second poll
     — the frames had already been transmitted and decoded by the time it ran, so it
     was decoration, not a control;
   - moved **base64 over plaintext**, so screen content was readable by anyone sniffing
     the LAN;
   - wrote **no monitoring tables at all**, so "who watched this student, and when" had
     no answer;
   - had a scheduled task bound to one `%USERNAME%` with no restart policy, so one
     network blip killed an agent for the rest of the session, and an uninstaller using
     `wmic` that silently did nothing on current Windows.

   **What replaced it.** No media relay at all. The Lab Client posts a frame to
   `/api/client/frame` over ordinary authenticated HTTP; the server overwrites one file
   per PC and broadcasts only a `pc_id`. The browser then fetches that one frame through
   `PcPolicy::viewScreen`. Three things follow: the websocket carries no image data, the
   teacher never receives bytes for a PC outside their class because each fetch is
   authorized on its own, and bandwidth tracks what the teacher looks at rather than
   what every PC captures.

   **Naming.** The PC-side software is the **Lab Client**, the staff view is **Live
   Screens**. Legacy called it an "agent", which reads as malware to non-technical staff
   and attracts endpoint-protection software on lab machines.

   **Scope.** A teacher sees only the PCs their own class is using; an administrator sees
   the whole lab. Both the grid and the frame endpoint resolve this through one
   `ScreenAccessService`, so they cannot disagree — which is exactly how legacy's
   filtered-UI-over-unfiltered-socket arrangement worked.

   **Audit.** Who viewed which PC, and when, is recorded as metadata. Frame bytes are
   never retained: one JPEG per PC, overwritten in place. A test asserts the audit count
   does not grow per frame fetch, since that would write thousands of identical rows a
   minute.

   **Kept from legacy:** `mss` + Pillow capture, the reused `BytesIO`, and client-side
   frame-drop giving a slow viewer natural backpressure. **Fixed:** `NEAREST` downscaling
   (aliased text) became BILINEAR; reconnect is infinite with capped exponential backoff
   resetting per drop rather than cumulatively; and a locked desktop now pauses capture
   instead of streaming a lock screen that looks live.

   **Deliberately out of scope for v1:** no audio (as legacy), single monitor per PC, no
   recording, and the solo view shows the same capture rate as the grid.

   Security item 23 is closed by design: the client authenticates with a per-machine
   token minted by an administrator and stored only as a hash. Deployment is documented
   in [`docs/reference.md`](../reference.md#8b-live-screens-optional).
2. **Broadcast — DONE, self-hosted.** Legacy was a stub with no backend. Chose a **self-hosted Laravel Reverb** server over Pusher: this is a single-school lab, and a hosted websocket would put student attendance and PC activity on someone else's infrastructure for a per-connection message quota. Four private channels, each authorized by a policy, with browser listeners wired on both panels. See [security debt #7](#6-security-debt-to-repay).
3. **The `messages` feature — DROPPED.** Legacy's controller was fully implemented (index, show, store, addAttachment, markAsRead, getUnreadCount, destroy) but no view ever linked to it, so it was unreachable in the app this replaces. Every legacy "Messages" nav item rendered group chat instead. Retired rather than given a UI: it would have meant three tables, two pages and a broadcast path for a feature nobody had reached, and group chat already covers every classroom use. The migration initially kept it and then reversed that — the tables and pages were removed rather than left dormant. See [schema-mapping](schema-mapping.md#messages-and-friends).
4. **Storage quota — 500 MB student / 2 GB teacher / 5 GB admin**, in `config('lab.storage')`. Legacy stated four different values.
5. **PC identity: IP.** Already the case in the built code; MAC is nullable and decorative, matching legacy's actual state.
6. **Session timeout — 120 minutes**, in `config('lab.session.lifetime_minutes')`. Legacy's documentation claimed 10, 30, 30 and 60 minutes in four places while the code ran a 10-minute sliding window. Filament 5 removed the panel-level `authTimeout()` knob, so the session lifetime is the only control and there is nothing for it to drift against.
7. **Accessibility — audit the real pages, don't port the legacy CSS.** `USER_MANUAL.md` specifies WCAG 2.1 AA; the legacy implementation is dead CSS and JS. Filament ships accessible components, so the risk sits in markup added on top. Reviewed and fixed the custom surfaces, with `tests/Feature/AccessibilityTest.php` pinning them.

### Still open

- **Reports** — referenced by four legacy documents and the entire legacy test suite; implemented nowhere.
- **Student attendance summaries** — *done*: `AttendanceSummaryService` plus a `MyAttendance` page on the student panel. The legacy view was unreachable, so this was rebuilt rather than ported.
- **Whole-folder upload (`webkitdirectory`)** — deliberately not built. Filament 5 has no helper for it, and browsers do not send relative paths in the multipart filename, so a server-side tree rebuild is impossible without custom client JavaScript. Left as a known gap rather than shipped half-working.
- **Echo listeners** — the server publishes and authorizes, but no browser subscribes yet. Realtime therefore still requires pressing Refresh.

---

## 8. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| The legacy app's own docs are unreliable — `AGENTS.md` references four classes and one controller that do not exist, and makes schema claims that are stale | High | Treat [legacy-system.md](./legacy-system.md) as the source of truth, not the legacy docs. It was derived from code. |
| Legacy test suite is a false signal — `FINAL-CHECKPOINT.md` and `COVERAGE-VERIFICATION.md` claim 100% pass and "PRODUCTION READY", but `IMPLEMENTATION-STATUS.md` says the admin and student suites **fail** on backend issues | High | Do not treat legacy test results as a parity baseline. Re-derive the checklist from the endpoint list. |
| Storage quota denormalization is already wrong in legacy (never decremented for message/group-chat files; `getStorage()` reads array keys that don't exist) | Medium | Recompute from `stored_files` in a command; never trust the counter on read |
| Physical disk structure and the DB folder tree can diverge — legacy `createFolderPath()` writes some subdirectories while `file_path` stays flat | Medium | Generate all paths from the DB tree; never from client input |
| Two panels means two sets of nav, widgets, and auth guards to keep in sync | Low | Share schema/table classes between resources where the model is shared |
| Filament `->strictAuthorization()` will surface missing policies as exceptions during development | Low | Intended. Add the policy rather than removing the flag |

---

## 9. Conventions

Boost guidelines are installed and live in `AGENTS.md`; they are the authority. This section records the project-specific decisions Boost cannot infer.

### Local environment — Laravel Herd

The app is served by **Herd**, not `artisan serve`. Herd derives the host from the parked directory name: `C:\Users\JD\Herd\KobenLabs` → `http://kobenlabs.test`.

| Concern | Decision |
|---|---|
| `APP_URL` | `http://kobenlabs.test` in both `.env` and `.env.example`. Anything generating absolute URLs depends on this being right |
| Web server | Herd's PHP-FPM. `php artisan serve` is redundant and binds the wrong port |
| `composer dev` | `schedule:work` + `queue:listen` + `npm run dev`. `serve` was removed |
| Scheduler | `php artisan schedule:work` drives `pcs:mark-stale-offline` on a 5-minute cadence. Herd does not run it |
| Queue | `database` driver. Nothing is queued yet — the broadcasts use `ShouldBroadcastNow` |
| Vite | Not required for the Filament panel, which ships its own assets. Only needed once custom CSS/JS is written |

### Baseline

| Concern | Convention |
|---|---|
| Formatting | `vendor/bin/pint` (Laravel preset; no `pint.json`). `--dirty` does not work — this is not a git repo |
| Tests | `php artisan test` (Pest 5). A `livewire()` helper lives in `tests/Pest.php`; `pest-plugin-livewire` is not a dependency |
| Model metadata | PHP attributes — `#[Fillable]`, `#[Hidden]`, `#[Scope]`, `#[ObservedBy]`, `casts()` method |
| Frontend | Tailwind 4 CSS-first; **do not** create `tailwind.config.js` |
| Indentation | 4 spaces, UTF-8, LF (`.editorconfig`) |
| Static analysis | **None installed.** No PHPStan, no Larastan, no Psalm |
| CI | **None.** No `.github/` |
| Resources | `make:filament-resource` works, but `make:filament-relation-manager` hangs even with `--no-interaction` — write those by hand |
| Filament properties | `$navigationGroup` is `string\|BackedEnum\|UnitEnum\|null` and `$navigationIcon` is `string\|BackedEnum\|null`. Declaring them `?string` is a fatal error |
| Accessors in Filament | `->relationship('rel', 'label')` cannot resolve an accessor — it tries to `ORDER BY` a column that does not exist. Provide a model helper returning an id → label map (`YearSection::options()`, `User::staffOptions()`) |
| Filament errors | Filament 5 has no `Schema::addErrors()`. Use Livewire's `$this->addError('data.field', $message)` then `$this->halt()` |

Filament 5 resources should use dedicated schema and table classes rather than large inline methods — `app/Filament/Resources/X/Schemas/XForm.php`, `XTable.php`, `XInfolist.php`, each with a static `configure()`, called from the resource's `form()`, `table()`, and `infolist()` methods. This keeps resources thin and lets relation managers reuse the same configuration.

> **Note on tests:** `phpunit.xml` forces `DB_CONNECTION=sqlite` with `:memory:`. If any migration uses MySQL-only DDL, tests will diverge from development. Prefer portable schema definitions.
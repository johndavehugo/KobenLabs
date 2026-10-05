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
| [feature-mapping.md](./feature-mapping.md) | Feature → Filament resource/page/widget mapping, endpoint mapping, parity checklist, security must-fix list |

---

## 1. Scope

### In scope
All 8 live feature areas of the legacy app: PC inventory, class sessions, attendance, schedules, year/sections, user management, personal file drive, group chat, and admin dashboard. 19 database tables. 61 HTTP endpoints. 4 WebSocket event types.

### Out of scope (or requiring an explicit product decision)
- **Broadcast** — legacy is a stub ("under development"). No backend, no client implementation.
- **Reports** — referenced throughout the legacy docs and tests, **does not exist in code**. No resource, no route, no view.
- **Student attendance summaries** — a 239-line view exists but is unreachable and its endpoint was never routed.
- **Screen monitoring** — covert screen streaming of student PCs. Implemented and working in legacy, but carries consent/legal implications. See [§7](#7-open-decisions).
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
| Message attachments | `uploads/messages/{messageId}/…` | `local` disk, `messages/{messageId}/…` |
| Group chat files | `uploads/group-chats/{groupId}/…` | `local` disk, `group-chats/{groupId}/…` |

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
| Group chat | `GroupChatController` + `admin|user/views/messages.js` | `GroupChatResource` + custom `ChatRoom` Livewire page |
| Remote monitoring | `MonitoringController` + Python agent + WS frames | **Decision required** — see [§7](#7-open-decisions) |
| Broadcast | Stub | Drop, or scope fresh |
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
| 3 — Storage and communication | File drive **done**: `FileService`, quota in one place, `MyDrive`, read-only `StoredFileResource`. Group chat, messages and the chat UI outstanding |
| 4 — Dashboards and parity | Not started |
| 5 — Realtime | Not started |

### Phase 0 — Prerequisites (do first)

The target app has two setup gaps that will corrupt any migration work started before they're fixed.

- [ ] Run `php artisan boost:install` — `AGENTS.md` still contains only the pre-install bootstrap placeholders. Boost will replace them with project-specific guidelines, and **will rewrite `AGENTS.md`**.
- [ ] Fix `.env.example` — it declares `DB_CONNECTION=sqlite` and comments out every credential, while the live app runs MySQL `koben_lab`. As written it will mislead every setup and CI step.
- [ ] Enable Filament auth features that legacy implicitly had: `->passwordReset()`, `->profile()`, `->registration()` (off — accounts are created by staff).
- [ ] Decide and record the answers to [§7](#7-open-decisions). Several block later phases.

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
- [ ] `group_chats` + members + messages + reads migrations and models.
- [ ] `GroupChatResource` + custom `ChatRoom` page.
- [ ] `messages` + recipients + attachments. **Legacy has no attachment download endpoint** — build one.
- [ ] Decide: keep the `messages`/`message_recipients` broadcast-mailbox feature, or retire it in favour of group chat? Legacy's admin "Messages" nav item is *already* just group chat.

#### What the file drive closed

`FileService` is the single gate every upload path goes through. Each item below was a
real legacy defect, and each has a test named after it.

| Legacy behaviour | Now |
| --- | --- |
| `ALLOWED_EXTENSIONS` and a 30-entry MIME list declared in `FileController` and never invoked | `assertAllowed()` checks the extension, the sniffed bytes, and the size |
| Group chat validated `$_FILES['type']`, which the client controls | `assertAllowed()` reads the file with `finfo`. `$file->getMimeType()` is deliberately **not** used: under `Illuminate\Http\Testing\File` it resolves from the *name*, so the check would validate the fixture rather than the payload |
| Message attachments allowed all file types (per an explicit comment) | Same allowlist as the drive |
| Quota enforced only on user file uploads, so message and group chat uploads were uncounted | `assertWithinQuota()` on every write, recomputed from `SUM(size)` rather than trusting the drifting `storage_used_mb` column |
| `FileService::deleteTree()` recursed in the database but unlinked only the top-level blob | Blobs for the node **and** every descendant are removed. `StoredFile::deleteTree()` had the same gap — it swept `descendantIds()`, which excludes the node itself, so deleting a single file leaked its blob |
| `Content-Disposition` built from the unsanitised `original_filename` | `Storage::download()` sets the header |
| Client supplied `folder_path`, which then disagreed with the written path | Path derived from the folder tree; a parent folder belonging to another account is refused |
| `Message::addAttachment()` omitted the NOT NULL `original_filename` column, so the insert always threw | Column supplied |

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

- [ ] Admin dashboard widgets (PC status doughnut, active session, occupancy).
- [ ] Student dashboard widgets (storage %, unread count, recent activity).
- [ ] Walk the parity checklist in [feature-mapping.md](./feature-mapping.md#6-parity-checklist).
- [ ] Port the verified legacy integration-test surface to Pest. Legacy's suites confirm only auth, users, PCs, schedules, messages, and files — see [legacy-system.md](./legacy-system.md#7-testing) for what is genuinely covered versus what merely appears to be.

### Phase 5 — Realtime

- [ ] `php artisan install:broadcasting`; pick Reverb or Pusher; publish `config/broadcasting.php`; uncomment the `broadcasting.echo` block in `config/filament.php`.
- [ ] Broadcast events: `PcStatusUpdated`, `ClassSessionStarted`, `ClassSessionEnded`, `StudentJoinedClass`, `GroupChatMessageSent`.
- [ ] Private channels with `routes/channels.php` authorization — **the legacy socket has no auth at all; do not port that**.
- [ ] Echo on both panels. `->broadcasting(false)` on the student panel if students should only poll.
- [ ] Port or retire the Python screen agent.

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

---

## 7. Open decisions

These need a product answer. Several block later phases.

1. **Screen monitoring — keep or drop?** Legacy streams student screens to teachers at up to 20 FPS over the LAN, gated on class attendance. It works, and `agent/screen_agent.py` plus a 30 MB vendored Python installer are committed. It also raises consent and legality questions that did not exist when this was a capstone project. **Recommendation: drop it.** Do not port the covert-streaming feature without an explicit decision.
2. **Broadcast — build or drop?** Legacy is a stub with no backend. Decide before Phase 3 so the resource list is final.
3. **Reports — build or drop?** Referenced by four legacy documents and the entire test suite; implemented nowhere.
4. **The `messages` feature — keep or retire?** Admin and student "Messages" nav items both render group chat. The `messages` / `message_recipients` / `message_attachments` tables power a separate broadcast-mailbox feature that the UI appears to have stopped using. **Recommendation: verify with the original authors, then probably retire.**
5. **Student attendance summaries** — a complete unreachable view exists. Build the endpoint, or delete the view?
6. **Storage quota values.** Legacy states them four different ways: 100/500/1000 MB (`config/config.php`), 500/2000 MB (`schema.sql` seeds), 100 MB for new users (`UserController::store()`), 500 MB (`system_config`). **Recommendation: 500 MB student / 2 GB teacher / 5 GB admin, in `config/lab.php`.**
7. **Session timeout.** Documented as 10 minutes, 30 minutes, 30 minutes, and 1 hour in four places.
8. **PC identity: IP or MAC?** Actual current state is IP-keyed with decorative MAC. `plans/mac-address-*.md` proposes MAC as the identity but was reverted.
9. **Accessibility.** Fully specified to WCAG 2.1 AA in `USER_MANUAL.md`; implemented only as dead CSS/JS. Build it, or accept Filament's defaults as the accessibility story?

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
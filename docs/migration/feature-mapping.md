# Feature Mapping — Legacy to Filament

Feature-by-feature mapping onto Laravel 13 + Filament 5, plus the parity checklist and the security fixes that must land alongside.

---

## 1. Filament structure

### Panels

| Panel | Path | Audience |
|---|---|---|
| `admin` | `/admin` | teacher, admin |
| `student` | `/student` | student |

Both need `->strictAuthorization()`. Without it Filament grants access when a policy or policy method is missing, which reproduces the legacy app's core problem.

### Resource tree

Use dedicated schema and table classes rather than large inline methods — Filament generates this layout, and it keeps resources thin and reusable from relation managers:

```
app/Filament/
├── Resources/
│   ├── Users/
│   │   ├── UserResource.php
│   │   ├── Schemas/UserForm.php
│   │   ├── Schemas/UserInfolist.php
│   │   ├── Tables/UsersTable.php
│   │   └── Pages/{List,Create,Edit}Users.php
│   ├── YearSections/…
│   ├── Pcs/…
│   ├── Schedules/…
│   ├── ClassSessions/…
│   ├── Attendances/…
│   ├── Files/…
│   └── GroupChats/…
├── Pages/
│   ├── MonitorClassSession.php
│   ├── MyDrive.php
│   └── ChatRoom.php
└── Student/
    ├── Resources/…
    └── Pages/Dashboard.php
```

### Pages versus resources

Filament resources cover CRUD well. Three legacy surfaces need **custom Livewire pages** because they are interactive consoles rather than record editors:

| Page | Why a resource is insufficient |
|---|---|
| `MonitorClassSession` | Live start/end controls, a PC occupancy grid, and a real-time attendance roster |
| `MyDrive` | Breadcrumb navigation, list⇄grid toggle, drag-and-drop upload, folder upload, sorting, quota bar |
| `ChatRoom` | Message stream, polling or realtime, composer, attachment upload, member list |

---

## 2. Feature mapping

### Admin panel

| Legacy feature | Legacy implementation | Target | Notes |
|---|---|---|---|
| Dashboard | `admin/views/dashboard.js`, 30 s polling | `Dashboard` + `StatsOverviewWidget`s | 4 PC stat cards + a doughnut. Legacy polls every 30 s; prefer realtime with polling as fallback |
| PC inventory | `PCController` + `admin/views/pcs.js` | `PcResource` | Status tabs become `Tabs` on the table; status colours become `->color()`; manage mode becomes row actions |
| Users | `UserController` + `users-api.php` + `admin/views/users.js` (66 KB) | `UserResource` + `YearSectionResource` | The legacy Students tab is really a year → section → student grid. Model as nested resources or relation managers |
| Schedules | `ScheduleController` + `admin/views/schedule.js` (46 KB) | `ScheduleResource` | Weekly grid becomes a `ScheduleResource` table plus a custom calendar page if the grid matters |
| Class sessions | `ClassSessionController` + `admin/views/class-sessions/*` | `ClassSessionResource` + `MonitorClassSession` page | |
| Attendance | `admin/views/attendance/*` | `AttendanceResource` (read-only) | Filters become `Filter` objects on the table |
| Messages | `admin/views/messages.js` (37 KB) | `GroupChatResource` + `ChatRoom` page | **This is group chat, not a message board** |
| Remote monitoring | `MonitoringController` + Python agent | **Decision required** | See [§5](#5-decision-dependent-features) |
| Broadcast | Stub | — | See [§5](#5-decision-dependent-features) |

### Student panel

| Legacy feature | Target | Notes |
|---|---|---|
| Dashboard | `Dashboard` + widgets | Storage-used bar and unread count become `StatsOverviewWidget`s |
| Join class | `JoinClass` page or `ClassSessionResource` view action | One button; idempotent via `firstOrCreate()` |
| File drive | `MyDrive` page + `FileResource` | See [§4](#4-filament-specific-implementation-notes) |
| Messages | `ChatRoom` page | Same component as admin, minus the create-group action |

### Auth

| Legacy | Target |
|---|---|
| `views/landing.html` portal picker | Redirect `/` → `/admin` or `/student` based on role |
| `AuthController::login` | Filament login |
| `AuthController::logout` | Filament logout |
| `AuthService::checkPermission` | Policy methods + `UserRole::canManageLab()` |
| Session timeout (4 conflicting values) | `config/session.php` `lifetime`, single value |
| Login rate limit (per-session, bypassable) | `RateLimiter` with a cache-backed counter |
| — | Add `->passwordReset()` and `->profile()`; both currently absent |

---

## 3. Endpoint mapping

The legacy app has 61 routes plus 12 legacy query-string actions. Most disappear — Filament resources replace hand-written CRUD. What remains is the behaviour Filament does not provide.

### Retained as first-class endpoints

| Legacy endpoint | Target | Reason |
|---|---|---|
| `POST /api/auth/login` | Filament auth | — |
| `GET /api/auth/check` | `Filament::auth()->user()` in a partial | Session probe for the SPA shell |
| `POST /api/heartbeat` | **Delete.** Filament is Livewire; there is no separate shell to keep alive | |
| `GET /api/csrf-token` | **Delete.** Laravel's CSRF is automatic | |
| `POST /api/class-sessions/{id}/join` | Livewire action on `MonitorClassSession` / `JoinClass` | |
| `PUT /api/class-sessions/{id}/end` | `EndSessionAction` | |
| `POST /api/schedules/check-conflicts` | Validation rule / `ScheduleObserver` | Not a round trip |
| `GET /api/files/{id}/download` | `DownloadAction` with `Storage::download()` | |
| `GET /api/files/quota` | Eloquent accessor + widget | |
| `POST /api/files/upload` | Livewire `FileUpload` with `RestrictsFileUploadsToSchemaComponents` | |
| `GET /api/monitoring/connected-agents` | **Decision required** | |
| `GET /api/group-chats/{id}/messages` | Livewire `Get` on `ChatRoom` | |
| — | **New:** message attachment download | Legacy has none |

### Deleted outright

| Legacy endpoint | Reason |
|---|---|
| `GET/PUT /api/users/{id}/accessibility` | Ownership was never checked. If rebuilt, scope to `auth()->user()` |
| `GET /api/files/storage` | Read array keys that do not exist; always returned `null` |
| `GET /api/pcs/cleanup-stale` | Becomes the scheduled `pcs:mark-stale-offline` command |
| `PUT /api/files/{id}/rename` | UI calls it; **no route ever existed**. Build it in `FileResource` or drop the UI affordance |
| `GET /api/attendance*` (3 endpoints) | UI calls them; **no route ever existed** |
| `users-api.php` (6 actions) | Session-only, no role check. Replaced by `UserResource` |
| `year-sections-api.php` (5 actions) | **No authentication at all.** Replaced by `YearSectionResource` |
| `download-group-file.php` | No group-membership check. Replaced by a policy-gated action |

### API surface decision

The legacy app is an SPA talking JSON. Filament is server-rendered Livewire. **Do not rebuild the 61-endpoint API** — build resources and pages, and add thin JSON endpoints only where an external consumer genuinely needs one.

If the Python screen agent is kept, it needs exactly one authenticated endpoint. See [§5](#5-decision-dependent-features).

---

## 4. Filament-specific implementation notes

### Resource scaffolding

`php artisan make:filament-resource` generates the schema/table class layout. Wire the generated classes together:

```php
// app/Filament/Resources/Users/UserResource.php
public static function form(Schema $schema): Schema
{
    return UserForm::configure($schema);
}

public static function table(Table $table): Table
{
    return UsersTable::configure($table);
}

public static function infolist(Schema $schema): Schema
{
    return UserInfolist::configure($schema);
}
```

### Upload security — mandatory

Filament 5's `InteractsWithSchemas` trait exposes Livewire's `_startUpload` and `_finishUpload` RPC methods, which **accept uploads to any property path by default** — not just registered upload fields. On any page using that trait, an attacker can tamper with a Livewire request to upload a file to an arbitrary property.

Add `RestrictsFileUploadsToSchemaComponents` to every custom Livewire component that uses `InteractsWithSchemas`:

```php
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;

class MyDrive extends Component implements HasSchemas
{
    use InteractsWithSchemas;
    use RestrictsFileUploadsToSchemaComponents;
    // ...
}
```

This matters more than usual here: the legacy app has **no upload type restriction** on two of its three upload paths, and its group-chat allowlist validates the client-supplied `$_FILES['type']`. Use `Rule::mimes()` **and** a server-side `finfo` check.

### Nested resources

The legacy Users view organises students as **year → section → student**. Filament's nesting supports this via `getParentResourceRegistration()`:

```php
public static function getParentResourceRegistration(): ?ParentResourceRegistration
{
    return YearSectionResource::asParent()
        ->relationship('students')
        ->inverseRelationship('yearSection')
        ->page('manageStudents');
}
```

### Relation managers

Where a resource form and a relation manager should look identical, reuse rather than duplicate:

```php
public function form(Schema $schema): Schema
{
    return FileResource::form($schema);
}

public function table(Table $table): Table
{
    return FileResource::table($table);
}
```

### Broadcasting

`->broadcasting(false)` on a panel disables Echo for that panel. `BROADCAST_CONNECTION=log` is the current setting and the `broadcasting.echo` block in `config/filament.php` is fully commented out — **nothing is delivered today**. Phase 5 only.

Filament also supports broadcast notifications via `Notification::make()->broadcast($recipient)`, useful for "you have been removed from a group chat" style alerts.

### Tailwind

Tailwind 4 is CSS-first with **no `tailwind.config.js`**. Do not create one. `resources/css/app.css` already has the two `@source` directives that scan Filament's compiled Blade in `storage/framework/views` — keep them.

Adding custom CSS for a Filament view means a separate Vite entry plus an `@vite` directive in a Blade view, **not** an edit to `app.css`. Filament serves its own version-pinned assets from `public/`, and those paths are gitignored and regenerated by `filament:upgrade`.

---

## 5. Decision-dependent features

Do not start these until the corresponding question in [README §7](./README.md#7-open-decisions) is answered.

### Remote monitoring

Legacy streams student screens to teachers over the LAN at up to 20 FPS. The pieces are: a Python agent using `mss` + `Pillow`, the Workerman relay, and a `<canvas>` grid in the teacher UI.

If rebuilt, do not port the original design:

- **Authenticate the agent.** Legacy accepts `agent_register` from any socket. Issue a signed token per PC and validate it.
- **Do not fan out to all teachers.** Legacy relays every frame to every connected teacher socket. Scope to the class session.
- **Push, don't poll.** Legacy uses a 5 MB `maxPackageSize` and a single-slot file mailbox. Use `ShouldBroadcastNow` on a private channel, or a dedicated WebSocket endpoint.
- **Do not commit the installer.** The repo carries a 30 MB `agent/python-3.14.3-amd64.exe`.
- **Get consent.** The feature is covert by design — students are not notified. This is a product and legal decision, not a technical one.

**Recommendation: drop it.**

### Broadcast

Legacy is a stub with no backend and two empty exported functions. Decide before Phase 3 so the resource list is final.

### Reports

Referenced by four legacy documents and the entire integration suite. Implemented nowhere — no resource, no route, no view. Decide whether to build or remove from the documentation set.

### Accessibility

Fully specified to WCAG 2.1 AA in `USER_MANUAL.md`; implemented only as dead CSS/JS. If building:

- `AccessibilitySetting` as a one-to-one relation on `User`, with a `RelationManager` on `UserResource`.
- Text size and high contrast as CSS custom properties. Tailwind 4's `@theme` block is the idiomatic place.
- Filament ships with a substantial accessibility baseline already (focus management, ARIA, keyboard navigation). Confirm what it covers before building custom.

If not building, delete the legacy spec from the requirements set rather than leaving it as an unmet promise.

### Student attendance summaries

A complete 239-line view exists at `public/js/user/views/attendance.js` — subject cards, Good/Fair/Low percentage thresholds, date filters — and it is imported by nobody, because its endpoint `/api/attendance/my-records` was never routed.

Two options: build the endpoint (a relation chain over `attendance → class_sessions → schedules`, grouped by subject, plus CSV export), or delete the view. Leaving it in limbo is not an option.

---

## 6. Parity checklist

Derived from the legacy endpoint list and feature inventory, **not** from the legacy test suite — see [legacy-system.md §8](./legacy-system.md#8-testing) for why the legacy coverage claims are unreliable.

### Phase 1 — Identity and structure

- [ ] Login by **username**, not email
- [ ] Three roles: student, teacher, admin
- [ ] Account status enforced at login: `inactive` and `suspended` cannot log in
- [ ] `admin` role exists and can see admin-only navigation — **legacy's seeded admin is `role = 'teacher'` and cannot**
- [ ] Password reset (legacy has the table but no working route)
- [ ] Role changes take effect immediately — legacy caches role in the session and never refreshes it
- [ ] Login rate limiting, cache-backed and per-username/IP (legacy: per-PHP-session, bypassable)
- [ ] Academic years and sections: create year, add section to year, soft delete, 409 on duplicate year with a helpful message, **reactivate rather than duplicate** an inactive row
- [ ] Deleting a year/section blocked while students are assigned
- [ ] Students can only be added once a year/section exists
- [ ] Pest policy-matrix tests covering all three roles

### Phase 2 — Labs and academics

- [ ] PC statuses are exactly `online | offline | maintenance`
- [ ] PC identity is the IP address; MAC is nullable and decorative
- [ ] Auto-detect PC on login by `REMOTE_ADDR`, skipping `127.0.0.1` and `::1`
- [ ] Logout sets the PC `offline` and clears `last_seen`
- [ ] Stale sweep: `online` PCs idle over 10 minutes go `offline`
- [ ] **`maintenance` PCs are never auto-offlined**
- [ ] Admin manual status override, with a realtime broadcast
- [ ] Schedule create / update / delete, teacher+admin only
- [ ] Teachers can **view** schedules but not create or delete (legacy gates this in the view only)
- [ ] Duration bounds enforced (30–480 min) from a single config value
- [ ] Conflict detection on overlapping schedules, excluding `cancelled`
- [ ] Start a class session from a schedule
- [ ] Only one active class session at a time — **confirm whether this is still intended**
- [ ] End a class session
- [ ] Student joins a class, writing one attendance row; repeat joins are no-ops
- [ ] Teacher sees the attendance roster, live-updating as students join
- [ ] Attendance history filterable by year/section and by subject
- [ ] Attendance is **read-only** in the admin panel

### Phase 3 — Storage and communication

- [ ] Per-user file tree with nested folders and breadcrumbs
- [ ] Upload single and multiple files, with progress
- [ ] Upload a whole folder (`webkitdirectory`)
- [ ] Drag-and-drop upload with an overlay
- [ ] List ⇄ grid view toggle
- [ ] Live search, and column sorting
- [ ] Image preview
- [ ] Download a file; download a folder as a ZIP
- [ ] Rename a file — legacy UI calls an endpoint that **never existed**
- [ ] Delete a file; delete a folder recursively, **removing physical child files too**
- [ ] Storage quota enforced on every upload path, including message and group-chat uploads — legacy counts only user files
- [ ] Quota bar with warn (>70 %) and danger (>90 %) thresholds
- [ ] Ownership enforced: a student sees only their own files; teacher and admin see all
- [ ] **MIME allowlist enforced server-side on every upload** — legacy has none on two paths
- [ ] Group chat: create group (teacher/admin only), 8-character join code
- [ ] Join by code; leave group; delete group (creator only, and the creator cannot leave)
- [ ] Group membership role badge, distinct from the user's global role
- [ ] Messages: text, file, image, system
- [ ] System messages on join and leave, with a NULL sender
- [ ] Read receipts
- [ ] Group chat file upload: 10 MB, allowlisted types
- [ ] **Group chat file download requires group membership** — legacy omits this check
- [ ] Message length capped at 2000 characters

### Phase 4 — Dashboards

- [ ] Admin dashboard: 4 PC stat cards (online / offline / maintenance / total) and a status doughnut
- [ ] Time-of-day greeting
- [ ] Student dashboard: storage-used percentage and unread message count
- [ ] Student dashboard: quick actions and a recent-activity list (files + messages merged)
- [ ] Both dashboards readable without JavaScript polling succeeding — i.e. degrade gracefully

### Phase 5 — Realtime

- [ ] `PcStatusUpdated` broadcast, teachers/admins only
- [ ] `ClassSessionStarted` / `ClassSessionEnded` broadcast
- [ ] `StudentJoinedClass` broadcast, teachers only — legacy sends it to students, who cannot subscribe
- [ ] `GroupChatMessageSent` broadcast, group members only
- [ ] All channels **authenticated**; legacy has none
- [ ] Concurrent broadcasts are not dropped — legacy's single-slot file mailbox loses them
- [ ] Client reconnects with exponential backoff, not 5 fixed-delay attempts then silence
- [ ] Student portal actually subscribes, or students are explicitly polling — legacy broadcasts to them and they never listen

---

## 7. Security must-fix list

Every item here is present in the legacy app today. Each needs a corresponding Pest test.

**Status: 16 of 24 addressed.** The policy layer (`app/Policies/`, pinned by `tests/Feature/PolicyMatrixTest.php`) closes items 2, 3, 5, 6, 8, 9, 10, 11, 16, 21 and 22 outright, and resolves items 1, 12, 13, 14 and 15 because stock Laravel and Filament already handle them. The rest need route or Livewire-level work.

| Remaining | Blocked on |
|---|---|
| 4 — `year-sections-api.php` deletion | Phase 1 resources; the policy exists, the script's replacement does not |
| 7 — WebSocket authentication | Phase 5 realtime |
| 17, 18, 19, 20 — upload validation and quota | Phase 3 `FileService` |
| 23 — screen agent token | Blocked on the [monitoring decision](#5-decision-dependent-features) |
| 24 — `AuditObserver` | Schema and `AuditLog::record()` are done; the observer is not written |

| # | Legacy issue | Target fix | Test |
|---|---|---|---|
| 1 | CSRF middleware written, loaded, **never registered** | Laravel's `PreventRequestForgery` (already active) | — |
| 2 | Role middleware written, loaded, **never instantiated** | Policies + `->strictAuthorization()` | Policy matrix |
| 3 | PC create/update/delete have **no role check** — any student can edit the inventory | `PcPolicy::create/update/delete` | Covered by the matrix |
| 4 | `year-sections-api.php` **unauthenticated**, `Access-Control-Allow-Origin: *` | Delete the script | Guest request → 401/403 |
| 5 | `users-api.php` **no role check** — any student can create/delete users and self-promote to admin | Delete; `UserResource` + `UserPolicy` | Covered by the matrix |
| 6 | `download-group-file.php` **no membership check**; raw `?path=` input | `GroupChatMessagePolicy::download` | Covered by the matrix |
| 7 | WebSocket **unauthenticated** — register as `teacher`, receive everything | Private channels via `routes/channels.php` | Unauthorised subscribe rejected |
| 8 | Accessibility endpoint has **no ownership check** | `AccessibilitySettingPolicy` (owner-only, staff included) | Covered by the matrix |
| 9 | `POST /api/class-sessions/{id}/join` — **no role check** | `ClassSessionPolicy::join` (active sessions only) | Covered by the matrix |
| 10 | `GET /api/class-sessions/{id}/attendance` — **no role check**, any user reads any roster | `AttendancePolicy::view` + `viewAnyForSession` | Covered by the matrix |
| 11 | `GET /api/messages/{id}` — **no recipient check** | `MessagePolicy::view` (sender or recipient; no admin override) | Covered by the matrix |
| 12 | CORS reflects `Origin` with `Allow-Credentials: true` | Laravel handles CORS | Done — never written |
| 13 | `display_errors=1` regardless of environment | `APP_DEBUG` | Done — stock Laravel |
| 14 | No `session_regenerate_id()` on login | Laravel's session guard | Done — stock Laravel |
| 15 | Login rate limit stored in `$_SESSION` | `RateLimiter` | Filament login |
| 16 | `User::$fillable` omits `year`/`section`, silently dropping them | Explicit fillable + `year_section_id` FK | Covered by the domain tests |
| 17 | No upload MIME restriction on two of three paths | `Rule::mimes()` + `finfo` | Phase 3 |
| 18 | Group chat allowlist validates client-supplied `$_FILES['type']` | Server-side `finfo` | Phase 3 |
| 19 | Quota bypassed via message and group-chat uploads | One `FileService` quota path | Phase 3 |
| 20 | `Content-Disposition` built from an unsanitised `original_filename` | `Storage::download()` sanitises | Phase 3 |
| 21 | `debug.php` is web-reachable and runs `ALTER TABLE` | Not ported | Done |
| 22 | Hardcoded credentials `admin` / `Admin123!` in legacy docs | No default accounts in seeders | Done |
| 23 | Screen agent socket unauthenticated; fake frames injectable | Token per PC, if the feature is kept | Blocked on the decision |
| 24 | `audit_logs` never written | `AuditLog::record()` + observer | API done, observer pending |

---

## 8. Test strategy

The target has Pest 5.3 with two placeholder tests. `tests/Pest.php` has `->use(RefreshDatabase::class)` **commented out** — uncomment it before writing any database test.

| Suite | Location | Covers |
|---|---|---|
| Unit | `tests/Unit/` | Enums, `FileService::checkQuota()`, schedule conflict logic, path generation, join codes |
| Feature | `tests/Feature/` | Every policy method, every Filament page render, every Livewire action |
| Security | `tests/Feature/Security/` | The table in [§7](#7-security-must-fix-list), one test per row |
| Browser | Dusk or Playwright | Join-a-class, upload-a-file, send-a-message |

Notes:
- `phpunit.xml` forces SQLite `:memory:`. Migrations must stay portable, or add a MySQL-backed CI job.
- Filament ships first-class testing helpers — `livewire()` assertions, `Filament::setCurrentPanel()`, `Filament::bootCurrentPanel()`. Use them rather than raw HTTP tests.
- Legacy contributes **no** reusable test code. Its 182 harness tests test the harness, and its integration suites call endpoints that never existed. Write tests from the parity checklist above.
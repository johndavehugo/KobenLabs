# Legacy System — As-Built Inventory

Reference for `capstone-proposal` (`C:\Users\JD\Herd\capstone-proposal`).

**This document was derived from the code, not from the repository's own documentation.** The legacy `AGENTS.md` / `DEVELOPER.md` / `USER_MANUAL.md` describe a larger system than exists — they reference four classes and one controller that do not exist, and make schema claims that are stale. Where the docs and the code disagree, this document follows the code.

---

## 1. Stack and structure

| Layer | Implementation |
|---|---|
| Language | PHP ≥ 8.0, **no framework** |
| Database | PDO + MySQL (XAMPP), database `csr_lab`, InnoDB, `utf8mb4` |
| Web server | Apache with `mod_rewrite`, XAMPP, port 80, `RewriteBase /capstone-proposal/` |
| Realtime | Workerman 4 on port 8080, bridged to PHP by a file mailbox |
| Frontend | Vanilla ES6 modules, Bootstrap 5.3, jQuery 3.7, Chart.js 4 |
| Asset pipeline | **None.** No Vite, webpack, esbuild, or Mix. Vendor libraries are `curl`-ed into `public/` at install time |
| Composer deps | `workerman/workerman ^4.1`, `tecnickcom/tcpdf ^6.6` |

`composer.json` maps a `CSRLab\\` PSR-4 namespace to `./src`, which **does not exist**. There is no autoloader for application code; every file is `require_once`'d manually from `index.php`.

### Request lifecycle

```
Browser → Apache:80 → .htaccess → index.php
  → utils\Router::dispatch()          first-match-wins over an ordered route array
  → AuthMiddleware                    global; identity check only
  → Controller closure → method → Model/Service → Response (echo + exit)
```

`utils/Router.php` iterates routes **in registration order and the first match wins**. Literal paths (`/files/storage`, `/pcs/online`, `/group-chats/join`) are carefully registered before their `{param}` siblings. Any route added out of order is silently swallowed by a parameter pattern.

Additional quirks in the router: it accepts a `?route=` query-parameter fallback; supports method spoofing via `POST` + `?_method=PUT` or `X-HTTP-Method-Override`; and strips a hardcoded `/capstone-proposal` base path from every URI.

### Entry points

| File | Role |
|---|---|
| `index.php` | Main entry, 552 lines: bootstrap, 61 route registrations, CORS, CSP, security headers |
| `api.php` | 9 lines — `require_once 'index.php'`, for hosts without `mod_rewrite` |
| `users-api.php` | Legacy query-string API, **bypasses the router, middleware, CSP and CORS** |
| `year-sections-api.php` | Legacy query-string API, **no authentication at all** |
| `download-group-file.php` | Direct-access download handler, **no group-membership check** |
| `websocket-server.php` | Workerman realtime server |
| `debug.php` | **Web-reachable; executes `ALTER TABLE pcs ADD mac_address` on every request** |
| `check_mac_column.php` | Diagnostic script, web-reachable |

### Layering

| Directory | Contents |
|---|---|
| `models/` | `BaseModel` (hand-rolled CRUD over PDO) + 9 concrete models |
| `controllers/` | 8 live controllers, 2 `.example.php`, 1 `.test.php` |
| `services/` | `AuthService`, `FileService`, `WebSocketService` |
| `middleware/` | `AuthMiddleware` (live), `RoleMiddleware` (**never instantiated**), `CSRFMiddleware` (**never registered**) |
| `utils/` | `Router`, `Response`, `Validator`, `CSRF`, `Logger`, `Sanitizer` (dead), `migrate.php` (**broken**), `health-check.php`, backup scripts |
| `config/` | `constants.php` (live), `database.php` (live), `config.php` (**never loaded**), `config.js` |
| `agent/` | Python screen-capture agent + vendored 30 MB Python installer |
| `database/` | `schema.sql` (authoritative), `schema.sql.backup` (stale), migrations, seeds |

`models/BaseModel.php` provides `find`, `findAll`, `create`, `update`, `delete`, `count`, `exists`, plus transaction proxies. There are **no relationships, no query builder, no scopes, no events, and no soft deletes.** `findAll()` only understands equality, `IN`, and `IS NULL`. `$orderBy` is string-concatenated, not parameterized.

`utils/migrate.php`, the migration runner, is **fatally broken** — it calls `Database::getInstance()`, but that class only exists inside `utils/health-check.php`. There is no working migration runner to port. Four `.sql` migrations must be applied by hand; `schema.sql` already contains their end state.

---

## 2. Database

19 tables in `database/schema.sql` (the authoritative source). No stored procedures, functions, triggers, or views anywhere.

| # | Table | Purpose | Model class |
|---|---|---|---|
| 1 | `users` | Accounts, roles, status, storage quota | `User` |
| 2 | `user_accessibility` | Text size / contrast / TTS preferences | **none** — raw SQL in `User` |
| 3 | `year_sections` | Academic year + section catalogue | `YearSection` |
| 4 | `schedules` | Class schedule / reservations | `Schedule` |
| 5 | `pcs` | Lab PC inventory, keyed by IP | `PC` |
| 6 | `sessions` | User ↔ PC occupancy history | **none** |
| 7 | `class_sessions` | Live teaching sessions | `ClassSession` |
| 8 | `attendance` | Per-session attendance | `Attendance` |
| 9 | `files` | Personal drive: files **and** folders in one self-referencing tree | `File` |
| 10 | `messages` | Broadcast mailbox messages — **dropped** | **dropped** |
| 11 | `message_recipients` | Mailbox read state | **dropped** |
| 12 | `message_attachments` | Mailbox attachments | **dropped** |
| 13 | `audit_logs` | Audit trail — **dead table, never written** | none |
| 14 | `system_config` | Runtime settings — **dead table, never read** | none |
| 15 | `group_chats` | Chat groups with join codes | `GroupChat` |
| 16 | `group_chat_members` | Membership + group-scoped role | **none** |
| 17 | `group_chat_messages` | Chat messages incl. system rows | **none** |
| 18 | `group_message_reads` | Read receipts | **none** |
| 19 | `migrations` | Legacy migration bookkeeping | **none** |

`database/schema.sql.backup` additionally contains `screen_sessions` and `screen_participants`, both **already dropped** from the live schema. Ignore the backup file. `utils/health-check.php` still asserts those two tables must exist, so it always reports false failures.

Column-level detail and the Laravel mapping: [schema-mapping.md](./schema-mapping.md).

### Schema oddities to resolve during migration

- **`sessions` name collision.** The legacy `sessions` table (user ↔ PC occupancy) shares a name with Laravel's DB-backed session table, which already exists in the target. The legacy table must be renamed. See [schema-mapping.md](./schema-mapping.md#6-sessions--pcsessions).
- `schedules` carries **both** `user_id` (creator) and `teacher_id`. `Schedule::getAll()` joins on `user_id` while `ClassSessionController::history()` filters on `teacher_id`. Pick one.
- `message_attachments.file_size` vs `files.file_size_bytes` — inconsistent naming for the same concept. Moot here: direct messages were dropped.
- `files.parent_folder_id` is `ON DELETE CASCADE` **and** `File::deleteRecursive()` recurses manually — double-delete semantics.
- "Only one active class session globally" is enforced in application code, not by a constraint.
- `audit_logs` and `system_config` are created and seeded but **never read or written** by any application code. All effective configuration lives in `config/constants.php`; `Logger::audit()` writes JSON lines to `logs/audit.log` instead of the table.

---

## 3. Features

### 3.1 Admin portal

Navigation is a hardcoded array in `public/js/admin/admin-portal.js`. There is **no feature-flag mechanism** anywhere — the only conditional is an `adminOnly: true` boolean that hides nav items from non-admins.

| Feature | Behaviour | Roles |
|---|---|---|
| **Dashboard** | 4 stat cards (PCs online / offline / in maintenance / total) + Chart.js doughnut with a custom HTML legend. 30 s polling | admin, teacher |
| **Class Sessions** | Active-session header with attendance modal and End Class, or "Start Class". PC grid with status tabs and per-PC occupancy badges. Realtime updates | admin, teacher |
| **Schedule** | Weekly timetable grid. **Role-gated in the view**: `isAdmin = role === 'admin'` hides add/delete for teachers | admin (edit), teacher (read) |
| **Attendance** | Read-only history: filter by year/section + subject, click a row for the roster | admin, teacher |
| **Messages** | **This is the group-chat app, not a message board.** Group list, chat pane with 3–5 s polling, file attach, members, delete, leave | admin, teacher (cannot create groups) |
| **Remote Monitoring** | Grid of `<canvas>` cards receiving base64 JPEG frames over WebSocket. Lazy-rendered via `IntersectionObserver`, click to expand. 10 s re-poll of the agent list | admin, teacher |
| **Broadcast** | **Stub.** Renders "This feature is currently under development." Two exported functions are empty no-ops | admin, teacher (still visible in nav) |
| **Users** | Two sub-tabs. *Students* tab is really a **year → section → student** management grid (add/delete years and sections, add/edit/delete students). *Staff* tab is a role-badged table. **Bypasses the main router** and calls the legacy root scripts directly | admin only |
| **PCs** | Status metric tabs, PC cards with colour-coded top borders, manage mode, add/edit/delete with a status select. Realtime via `onPcStatusUpdate` | admin only |

The admin app **does not load Bootstrap CSS**. It uses hand-written `.card` / `.btn` / `.badge` / `.modal` / `.form-*` classes defined in `admin.css` and `common.css` (~54 KB).

### 3.2 Student portal

| Feature | Behaviour |
|---|---|
| **Dashboard** | Storage-used % bar (blue < 70 %, yellow 70–90 %, red > 90 %) and unread message count, plus Quick Actions and a Recent Activity list merged from the 5 newest files and messages |
| **Class Sessions** | Shows the active session; **Join Class** button writes attendance, or "✓ Joined" if already recorded |
| **File Drive** | Google-Drive-style personal drive across 11 JS modules: breadcrumbs, New menu (upload files / upload folder via `webkitdirectory` / new folder), live search, list⇄grid toggle, multi-file upload with progress bar, drag-and-drop overlay, file menu (rename / download / delete / image preview), column sorting, quota bar |
| **Messages** | Same group-chat app as admin, with `canCreateGroups = false` |

### 3.3 PC lifecycle — the most distinctive business logic

Must survive migration intact.

- Statuses are `online | offline | maintenance`. **Never `available`**, despite what the docs say.
- PCs are keyed by **IP address** (`UNIQUE`), not MAC. `mac_address` is nullable and explicitly "for admin reference only".
- **Auto-detect on login**: match `$_SERVER['REMOTE_ADDR']` against `pcs.ip_address`; skip `127.0.0.1` and `::1`. On match → set `online`, update `last_seen`, record a `sessions` row, broadcast `pc_status_update`. On logout → set `offline`, clear `last_seen`, broadcast.
- **Heartbeat** every 5 minutes from the admin portal refreshes the session and updates `last_seen`.
- **Stale sweep**: any `online` PC with `last_seen` older than 10 minutes → `offline` + broadcast. **`maintenance` PCs are never auto-offlined.**
- **Manual override**: an admin can set any status via `PUT /api/pcs/{id}`, which broadcasts.
- Card colours: online `#10b981`, offline gray, maintenance `#ef4444`.

### 3.4 Class sessions and attendance — the core academic loop

1. A teacher starts a class from a **schedule** → `POST /api/class-sessions`.
2. `GET /api/class-sessions` returns the single active session together with the PC list and an occupancy map.
3. Students press **Join Class** → `POST /api/class-sessions/{id}/join` → an `attendance` row (`UNIQUE(class_session_id, user_id)`). Idempotent; the UI pre-checks and shows "✓ Joined".
4. The teacher's attendance modal **live-appends** joiners via the `student_joined` WebSocket event.
5. Teacher ends the class → `PUT /api/class-sessions/{id}/end` → `class_session_ended` broadcast.
6. The admin **Attendance** view is read-only history, filterable by year/section and subject.

### 3.5 Year and section catalogue

`year_sections(year, section, is_active)` with `UNIQUE(year, section)`. Deletion is soft (`is_active = 0`). Creating a duplicate year returns 409 with "Year already exists. Please add a section to this year instead."; an inactive row is **reactivated** rather than duplicated. Deleting a year or section is blocked while students are assigned to it. Students are organised **year → section → student**, and the year/section structure must exist before a student can be added.

### 3.6 Group chat

Teacher/admin creates a group; the server generates an 8-character join code; anyone joins by code. Membership carries a group-scoped role (`admin | member`) that is independent of the user's global role. Messages are `text | file | image | system`; `system` rows have a NULL `sender_id` and record joins and leaves. A `reply_to_id` threading column exists in the schema but is unused by the UI. Attachments are capped at 10 MB against a 9-entry MIME allowlist.

### 3.7 Remote monitoring — covert screen streaming

A Python agent (`agent/screen_agent.py`) captures the primary monitor with `mss`, hides the Windows cursor around each grab, resizes, JPEG-encodes, base64-encodes, and streams to the server at up to **20 FPS** by default (`agent/config.txt`). The server relays every frame to **all** connected teacher sockets with no per-teacher session scoping, no rate limiting, and no backpressure. The teacher view renders them as `<canvas>` thumbnails (640×360) with a 2× expanded modal.

Visibility is gated by class attendance rather than raw session: `GET /api/monitoring/connected-agents` cross-references `attendance → class_sessions` (and the legacy `sessions → pcs`) and returns only matching PC IPs.

The socket accepts `agent_register` from **any** unauthenticated client, so fake frames can be injected.

### 3.8 Accessibility — specified but not implemented

`USER_MANUAL.md` §5 specifies text sizing, a high-contrast mode, text-to-speech, an `Alt+S` skip link, visible focus outlines, ARIA labelling, modal focus trapping, screen-reader support, and 200 % zoom without horizontal scroll, targeting WCAG 2.1 AA.

What actually exists: `.skip-link`, `aria-*` attributes in the sidebar/tabs/modal components, `Utils.announceToScreenReader`, a modal focus trap, and a 768 px breakpoint.

What does not exist: the entire accessibility panel. `public/js/accessibility.js`, `public/css/accessibility.css`, the `Alt+S` shortcut, and `services/AccessibilityService.php` (referenced by `AGENTS.md` but never written) are all absent or unloaded. Meanwhile `GET|PUT /api/users/{id}/accessibility` is live with **no ownership check** — any authenticated user can read or write any other user's preferences.

---

## 4. API surface

61 routes registered in `index.php`: 5 public, 56 protected. Plus 12 legacy query-string actions in 3 standalone scripts that bypass the router entirely.

### Public routes

| Method | Path | Notes |
|---|---|---|
| POST | `/api/auth/login` | Also flips the matching PC to `online`, writes a `sessions` row, broadcasts. Rate limit: 5 / 60 s |
| POST | `/api/auth/logout` | **Public** — no auth required to call. Reverses the PC side effects |
| GET | `/api/auth/check` | Session probe. Always HTTP 200 |
| GET | `/api/csrf-token` | Returns `{name, value}`; the JS client reads `data.token` — **mismatch**, so every mutating request sends `X-CSRF-Token: undefined` |
| POST | `/api/heartbeat` | **Unauthenticated.** Touches the session and updates `last_seen` |

### Protected routes by area

| Area | Endpoints |
|---|---|
| Auth | `POST /api/auth/refresh` |
| Users | `GET/POST /api/users`, `GET/PUT/DELETE /api/users/{id}`, `GET/PUT /api/users/{id}/accessibility` |
| PCs | `GET/POST /api/pcs`, `GET /api/pcs/online`, `GET /api/pcs/cleanup-stale`, `GET/PUT/DELETE /api/pcs/{id}` |
| Files | `GET /api/files`, `GET /api/files/storage`, `GET /api/files/quota`, `POST /api/files/upload`, `POST /api/files/folder`, `GET /api/files/{id}`, `GET /api/files/{id}/download`, `DELETE /api/files/{id}` |
| Schedules | `GET/POST /api/schedules`, `GET/PUT/DELETE /api/schedules/{id}`, `POST /api/schedules/check-conflicts` |
| Messages | `GET /api/messages`, `GET /api/messages/unread-count`, `GET /api/messages/{id}`, `PUT /api/messages/{id}/read`, `POST /api/messages`, `POST /api/messages/{id}/attachments`, `DELETE /api/messages/{id}` |
| Class sessions | `GET /api/class-sessions`, `GET /api/class-sessions/history`, `GET /api/class-sessions/{id}`, `POST /api/class-sessions`, `PUT /api/class-sessions/{id}/end`, `POST /api/class-sessions/{id}/join`, `GET /api/class-sessions/{id}/attendance` |
| Monitoring | `GET /api/monitoring/connected-agents` |
| Year sections | `GET /api/year-sections` |
| Group chats | `GET/POST /api/group-chats`, `POST /api/group-chats/join`, `GET /api/group-chats/download-file`, `GET/DELETE /api/group-chats/{id}`, `GET/POST /api/group-chats/{id}/messages`, `POST /api/group-chats/{id}/files`, `GET /api/group-chats/{id}/members`, `POST /api/group-chats/{id}/leave` |

**There is no message-attachment download endpoint.** Attachments are listed but cannot be retrieved.

### Legacy standalone APIs

| Script | Auth | Actions |
|---|---|---|
| `users-api.php` | `$_SESSION['user_id']` only — **no role check** | `list`, `list-students`, `list-staff`, `create`, `update`, `delete`. This is what the admin Users view actually calls. Reactivating a deactivated user instead of inserting a new one is deliberate behaviour |
| `year-sections-api.php` | **None.** Sends `Access-Control-Allow-Origin: *` | `list`, `create`, `update`, `delete`, `add-section`. Unauthenticated read **and write** on `year_sections` |
| `download-group-file.php` | `$_SESSION['user_id']` only — **no membership check** | Takes a raw `?path=`, rejects `..` and any path not prefixed `uploads/group-chats/` |

### Endpoints the frontend calls that do not exist

`GET /api/attendance`, `GET /api/attendance/count`, `GET /api/attendance/my-records`, `PUT /api/files/{id}/rename`, `GET|PUT /api/users/accessibility`. Each produces a broken UI path.

### Response conventions

`utils/Response.php` emits `{success, message?, data?}` or `{success: false, error, errors?}` and every method `echo`s then `exit`s. At least eight call sites pass `201` into the `$message` positional slot, silently returning **HTTP 200 with `"message": 201`**. `validationError()` returns 400 while some controllers return 422 for the same failure class. Several controllers bypass `Response` entirely with inline `http_response_code()` + `echo` + `exit`, producing a second, incompatible response shape.

### CSRF and auth tokens

`utils/CSRF.php` generates a 64-character hex token into `$_SESSION`, and `CSRFMiddleware::validate()` would check it from the POST body, the `X-CSRF-Token` header, or the JSON body. **The middleware is loaded in `index.php` and never registered**, so CSRF is not enforced anywhere.

`AuthService::createSession()` also mints a 32-byte hex token into `$_SESSION['token']`, which is **never checked on any incoming request**. Authentication is purely `$_SESSION['user_id']` plus a `last_activity` timeout.

---

## 5. Files and storage

Three independent zones, each with its own path convention and **its own validation policy**:

| Zone | Path | Max size | Type restriction |
|---|---|---|---|
| User files | `uploads/users/{userId}/{md5_8}_{name}.{ext}` | 50 MB | **None.** `ALLOWED_EXTENSIONS` (25), `isAllowedMimeType()` (30 MIME types), and `Validator::validateFileType()` are all declared and **never invoked** |
| Message attachments | `uploads/messages/{messageId}/uniqid()_{name}` | 50 MB | **None** — explicit comment: "allow all file types" |
| Group chat files | `uploads/group-chats/{groupId}/uniqid()_{time}.{ext}` | 10 MB | 9 MIME types, validated against **client-supplied** `$_FILES['type']` |

Folder structure: the DB tree is self-referencing via `files.parent_folder_id`, with folders stored as rows using `mime_type = 'folder'`, `file_path = ''`, `file_size_bytes = 0`. The **physical** layout is flat per user — `createFolderPath()` writes some subdirectories, but `file_path` for a stored file stays flat, so disk and DB can diverge.

Deleting a folder recurses in the DB but `unlinks` only the top-level physical file — **child files leak on disk**.

Quota accounting is denormalized onto `users.storage_quota_mb` and `users.storage_used_mb`, incremented only on user-file upload and decremented on delete. Message and group-chat uploads are **never counted**. `FileController::getStorage()` reads `used_bytes` / `quota_bytes`, keys that `checkQuota()` does not return, so it always responds with `null`.

---

## 6. Role → capability matrix (as actually enforced)

Three roles: `student`, `teacher`, `admin`. **No `technician` role exists** — monitoring is handled by teacher/admin.

The enforcement mechanism is a `private requireTeacherRole()` helper duplicated verbatim in three controllers, plus four inline role checks. There is no permission matrix.

| Capability | student | teacher | admin | Enforcement |
|---|:--:|:--:|:--:|---|
| Own files: list / upload / download / delete / folder / quota | ✓ | ✓ | ✓ | `checkOwnership()` |
| Any user's files | ✗ | ✓ | ✓ | `checkOwnership()` |
| View schedules | ✓ | ✓ | ✓ | none |
| Create / update / delete schedule | ✗ | ✓ | ✓ | `requireTeacherRole()` |
| User CRUD | ✗ | ✓ | ✓ | `requireTeacherRole()` |
| View any single user | ✓ | ✓ | ✓ | **none** |
| Read/write accessibility of any user | ✓ | ✓ | ✓ | **none** |
| **PC create / update / delete** | ✓ | ✓ | ✓ | **none** |
| PC list / online / cleanup-stale | ✓ | ✓ | ✓ | none |
| Send message, add attachment | ✗ | ✓ | ✓ | `requireTeacherRole()` |
| Delete message | own only | own only | ✓ | sender-or-admin check |
| Start / end class session | ✗ | ✓ | ✓ | inline check |
| **Join class / record attendance** | ✓ | ✓ | ✓ | **none** |
| **Read the attendance list** | ✓ | ✓ | ✓ | **none** |
| Monitoring / connected agents | ✗ | ✓ | ✓ | inline check |
| Create group chat | ✗ | ✓ | ✓ | inline check |
| Join / leave group chat | ✓ | ✓ | ✓ | none |
| Delete group | ✗ | own-created | own-created | `created_by` check |
| **`year-sections-api.php` (all actions)** | ✓ | ✓ | ✓ | **no auth at all** |
| **`users-api.php` (all actions)** | ✓ | ✓ | ✓ | **session only, no role check** |
| `download-group-file.php` | ✓ | ✓ | ✓ | **session only, no membership check** |

`RoleMiddleware` implements a hierarchy (`admin` 3 > `teacher` 2 > `student` 1) but is **never instantiated**, so it plays no part.

Two seed-data gotchas: the seeded `admin` account has `role = 'teacher'`, and since `admin-portal.js` hides `adminOnly` nav items from non-admins, **the default admin account cannot see the Users or PCs nav**. The session timeout is also documented as 10, 30, 30, and 60 minutes in four different places.

---

## 7. Realtime

Workerman on `websocket://0.0.0.0:8080`, `count = 1` (single process, all state in memory), `maxPackageSize` 5 MB for base64 frames. Launched by `start-websocket.bat` / `.sh`.

### Bridge

HTTP controllers **never open a socket**. `WebSocketService` writes one JSON object to `tmp/ws_broadcast.json` under `LOCK_EX`; the worker polls that file every 100 ms, `@unlink`s it, and fans it out. It is a **single-slot mailbox**, so concurrent broadcasts overwrite each other and events are silently dropped. This is the only shared state between the Apache workers and the WebSocket process.

### Client → server

| Type | Payload | Behaviour |
|---|---|---|
| `register` | `{user_id, role, username?}` | Routes into the teacher or student registry. **Unauthenticated** — anyone can claim `teacher` |
| `agent_register` | `{ip_address?, pc_name?}` | Registers a screen-capture agent. **Unauthenticated** |
| `agent_screen_frame` | `{frame}` | Re-emitted as `student_screen_frame` to **every** teacher connection |
| `ping` | — | Replies `pong` |

### Server → client

| Type | Recipients | Trigger |
|---|---|---|
| `pc_status_update` | teachers/admins | PC login, logout, manual status change, stale sweep |
| `class_session_started` | **all clients** | `POST /api/class-sessions` |
| `class_session_ended` | **all clients** | `PUT /api/class-sessions/{id}/end` |
| `student_joined` | **all clients** | `POST /api/class-sessions/{id}/join` |
| `student_screen_frame` | teachers/admins | Python agent |

### Client

`public/js/core/ws-client.js` hardcodes `` `ws://${location.hostname}:8080` ``. Fixed 3 s reconnect delay, 5 attempts, then it gives up silently — no backoff, no jitter, no manual retry. A 30 s ping keeps the connection warm.

### Who actually listens

| Surface | Realtime |
|---|---|
| PCs view | `onPcStatusUpdate` → updates a single card |
| Class Sessions view | session started/ended → full re-render; `student_joined` → appends to the open attendance modal |
| Remote Monitoring | `onStudentScreenFrame` → canvas draw |
| Dashboard | **none** — 30 s polling |
| **Student portal** | **never connects at all** — `user-portal.js` does not call `WSClient.connect()` |
| Group chat | **none** — 3–5 s polling |

The server broadcasts `class_session_started`, `class_session_ended`, and `student_joined` to students, but **no student-side code subscribes to any of them**. Students learn about a new class only by opening the view manually.

Both portal shells also load their entry module **twice** — once via `<script src>` and once via an inline `import` — registering two `DOMContentLoaded` listeners. `init()` therefore runs twice, opening **two WebSocket sockets** and two heartbeats.

---

## 8. Testing

98 `*.test.js` files. Jest with jsdom for units, Jest with node for integration, Playwright for some browser specs. There are **no PHP tests at all**, and no CI for PHP.

### Config problems

- `jest.config.js` has `testMatch: ['**/tests/**/*.test.js']` with no exclusion for `tests/integration/**`, so **`npm test` attempts to run integration suites in jsdom**.
- Five unit test files `import()` modules that **do not exist** (`admin/views/sessions.js`, `admin/views/reports.js`, `user/views/schedule.js`) — guaranteed `ERR_MODULE_NOT_FOUND`.
- `admin/dashboard.integration.test.js` and all three `cross-browser/*.integration.test.js` are authored with Playwright's `test.describe` but are matched by the Jest config.
- 182 of the tests are unit tests **of the test harness itself** (`helpers/backend-client.test.js`, `test-data-factory.test.js`, `execution-reporter.test.js`).

### What is genuinely verified

`tests/integration/helpers/backend-client.js` — the HTTP client every integration suite uses — targets an API that **no longer exists**. It calls:

| Called by tests | Actual router |
|---|---|
| `GET /api/pcs/available` | `GET /api/pcs/online` |
| `/api/sessions/*` (6 endpoints) | `/api/class-sessions/*` |
| `/api/attendance/*` (5 endpoints) | **none** |
| `/api/reports/*` (5 endpoints) | **none** |
| `/api/screen/*` (8 endpoints) | **none** |
| `POST /api/test/query`, `/api/test/reset-database` | **none** (test-only, not gated by `TEST_MODE`) |

Conversely, **no integration test touches** the entire group-chat feature, class sessions, monitoring, year sections, `pcs/cleanup-stale`, the heartbeat, or any of the three legacy standalone scripts.

**The real verified-parity surface is therefore:** auth, users CRUD, PCs CRUD, schedules CRUD plus conflict checking, messages CRUD plus attachments and unread count, and files CRUD plus quota and upload/download — together with authorization and isolation tests for those areas.

Everything else — class sessions, attendance, monitoring, group chat, year/section management, and reports — is **either unverified or tested against endpoints that never existed.**

### Treat the coverage claims as fiction

`FINAL-CHECKPOINT.md` and `COVERAGE-VERIFICATION.md` claim 50/50 suites, 100 % pass, 52/52 endpoints, 135/135 integration points, and "PRODUCTION READY". Both are dated 2024-01-15 and pre-date the current code. `tests/integration/IMPLEMENTATION-STATUS.md` states the opposite: the admin and student portal suites **fail on backend issues**, with missing helper methods, base-URL navigation failures, and 401-where-403-expected mismatches.

Trust `IMPLEMENTATION-STATUS.md` and the file listing. Do not use the legacy suite as a parity baseline.

---

## 9. Dead and broken code

Do not port any of this.

| Item | Status |
|---|---|
| `public/js/admin/views/broadcast.js` | Stub — "under development" |
| `public/js/admin/views/attendance.js` (root) | Stub, never imported |
| `public/js/user/views/attendance.js` | **239-line complete view, imported by nobody**, calls an unrouted endpoint |
| `public/js/screen.js` | 15 KB `getDisplayMedia` class, never instantiated, uses `module.exports` in a browser file |
| `public/js/accessibility.js` + `public/css/accessibility.css` | Referenced by no HTML |
| `public/js/common.js` | ES module loaded as a classic script by two already-broken pages |
| `public/js/user.js` | 69 KB legacy monolith with mojibake glyphs, for two broken pages |
| `public/js/vendor/socket.io.min.js` | Socket.IO abandoned for raw WebSocket |
| `public/js/vendor/datatables.min.{js,css}` | Replaced by a hand-rolled `DataTable` |
| 16 of 17 `public/css/files/*` partials | Not imported |
| `utils/Sanitizer.php` | Loaded by nothing |
| `utils/migrate.php` | Fatally broken |
| `utils/public/**` | 7 empty directories |
| `views/user/files.html`, `views/user/messages.html` | Broken — `CSRLab` is undefined repo-wide |
| `views/index.html` | Broken — calls an `apiRequest()` that does not exist |
| `views/errors/{403,404,500}.html` | Never served by any PHP |
| `admin/views/messages.js.old` | Backup copy |
| `agent/python-3.14.3-amd64.exe` | 30 MB installer committed to the repo |
| `screenshots/` | Stray artifacts |
| Mojibake | `user.js`, `admin/views/pcs.js`, `admin/views/schedule.js`, `user/views/files/upload-handler.js` ship broken characters in user-visible strings |

### Additional broken behaviour worth knowing

- `Message::addAttachment()` omits the `NOT NULL` `original_filename` column → guaranteed SQL error. Moot here: direct messages were dropped, though it confirms the mailbox was never exercised.
- `FileController::getStorage()` reads array keys `checkQuota()` does not return → always `null`.
- `PCController::store()` and 7 other call sites pass `201` into the `$message` slot → HTTP 200.
- `GroupChat::delete()` **overrides** `BaseModel::delete()` with different semantics — a landmine when converting to Eloquent.
- `BaseModel::hasColumn()` caches a `static` inside an instance method, so sibling models share one `DESCRIBE` result.
- `GroupChat::generateUniqueJoinCode()` uses `str_shuffle`, which is not cryptographically strong. Use `Str::random()`.
- `config/config.php` (~30 constants) is **never loaded by the web app**, and double-defines `APP_ENV` against `config/constants.php`. Every value is re-hardcoded in three or four places.
- `index.php` hardcodes `date_default_timezone_set('Asia/Manila')` and `display_errors = 1` regardless of environment.

---

## 10. Deployment assumptions

| Port | Service | Configured in |
|---|---|---|
| 80 | Apache / XAMPP | XAMPP `httpd.conf`, `.htaccess` `RewriteBase` |
| 3306 | MySQL | XAMPP `my.ini`, `config/database.php` |
| 8080 | Workerman WebSocket | **Three files must agree**: `websocket-server.php:19`, `public/js/core/ws-client.js:13`, and the CSP `connect-src` in `index.php:43` |

Deployment target is **LAN**, not the internet: multiple lab PCs connecting to one central XAMPP server. `websocket-server.php` hardcodes port 8080 and ignores the `TEST_WS_PORT` env var that CI sets to 8081. The CSP `connect-src` is built from `HTTP_HOST`, so serving on a non-standard port breaks LAN WebSockets — a pitfall the legacy `AGENTS.md` explicitly warns about.

The CSP header is only emitted by `index.php`, meaning **only API responses get security headers**; the static HTML pages get none. It also sets `script-src 'self'` while every HTML page contains inline `<script>` blocks.
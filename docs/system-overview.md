# KobenLabs — System Documentation

> Written for the development team to adapt into capstone paper chapters
> (background of the study, objectives, scope, system features, architecture).
> Fill in school, team, and adviser details where marked.

## 1. System Overview

**KobenLabs** is a computer-laboratory management system for schools. It gives
teachers and administrators one web application to manage lab computers, run
class sessions, track attendance, share files, communicate through group chat,
and watch student screens live — while students get their own panel for joining
classes, storing files, chatting, and checking attendance.

The system replaces a legacy framework-less PHP/PDO application with a modern
stack (Laravel + Filament). It is built for a single computer laboratory served
over the school's local network: one server PC runs the application, and every
lab PC runs a lightweight background client.

## 2. Background

The legacy system was a framework-less PHP application using raw PDO and
vanilla JavaScript. It worked, but an audit during migration documented
structural problems that motivated the rewrite:

- Client credentials stored as plaintext tokens, with a self-service
  registration endpoint anyone could call.
- No authorization checks on several screens and downloads (including a
  path-traversal file download and unescaped data rendered into pages).
- The lab client ran as a scheduled task bound to one Windows username with no
  restart policy, and uninstall relied on a tool removed from current Windows.
- Each lab PC installed its own Python and dependencies, so machines drifted
  apart; there was no single reproducible client build.
- The audit log table existed but was never written to; events went to a text
  file nobody read.
- Direct messaging tables, global schedule conflict checks, and other features
  no longer matched how the lab actually operates.

KobenLabs keeps what the legacy system got right (task-based client, per-PC
monitoring, class workflows) and re-implements it with hashed credentials,
policy-gated access everywhere, a single frozen client binary, queued audit
writes, and automated tests (over 420 tests).

## 3. Objectives

General objective: provide the school's computer laboratory with a secure,
centralized management system that covers machines, classes, files,
communication, and live supervision.

Specific objectives — the system must allow an administrator or teacher to:

1. Manage user accounts, year levels, and sections.
2. Inventory lab computers and see which are online, idle, or under
   maintenance, including who is using each machine.
3. Supervise student screens live from a staff page (Live Screens).
4. Book class schedules with conflict detection and run live class sessions.
5. Record and review student attendance automatically as students join.
6. Give every student a personal file drive with quotas and safe file types.
7. Provide group chat with file sharing, read tracking, and typing indicators.
8. Keep an audit trail of who changed what, and when.

## 4. Scope and Limitations

In scope: one laboratory, three user roles (student, teacher, administrator),
web access over the local network, Windows lab PCs for the monitoring client,
and MySQL as the database.

Limitations:

- Live Screens supports Windows lab PCs only (the client captures the Windows
  desktop).
- The shared-network setup serves over plain HTTP; screen content on the wire
  should be treated accordingly (see Deployment).
- One class session may be active at a time (configurable).
- There is no self-registration; accounts are created by staff.
- There is no built-in whole-folder upload, PC auto-detection on login, or
  legacy-data importer.
- There is no technician role; fleet operations belong to the administrator.

## 5. System Users

| Actor | Access | Can do |
|---|---|---|
| Student | `/student` panel | Join a running class; manage a personal file drive; join group chats with a code and send messages/files; view personal attendance summary |
| Teacher | `/admin` panel | Everything except user deletion edge cases and history deletion: manage PCs and clients, view Live Screens for their own class's PCs, book schedules, start/end their sessions, view their rosters, create group chats, view all files (read-only) |
| Administrator | `/admin` panel | All teacher abilities over the whole lab (all PCs, all rosters), plus user management, history deletion, and client enrolment/revocation |

Only active accounts can sign in. Suspended or inactive accounts are locked
out. Staff and student panels are fully separate; no account can use both.

## 6. Features by Module

### 6.1 User and Section Management

- Create, view, edit, and delete user accounts (first/last name, username,
  email, password of at least 8 characters, role, status).
- Students carry a unique student ID and a year-section assignment; teachers
  and administrators do not.
- Per-user storage quotas with live usage (students 500 MB, teachers 2 GB,
  administrators 5 GB by default).
- Year-section catalogue (e.g. "BSIT 1 — A") with active/inactive flag;
  inactive sections disappear from selects while history is kept.
- Students can be added to a section directly from the section's page.
- Safeguards: nobody can delete or suspend their own account; passwords are
  never shown back.

### 6.2 PC Inventory and Presence

- Register each lab PC with a name, fixed IP address (its identity), and
  optional MAC address for reference.
- Live status per PC: online, offline, or maintenance (maintenance machines are
  exempt from automatic idle collection).
- Presence is detected from sign-ins: when a user logs in from a lab PC's IP,
  that PC shows online and "in use by" that user; on logout it goes offline
  when nobody remains. PCs idle past 10 minutes are collected automatically
  every 5 minutes, or on demand with "Collect idle PCs".
- Staff can override a PC's status manually (e.g. mark maintenance).

### 6.3 Live Screens (Supervision)

- A staff page shows one tile per machine: machine name, the student sitting at
  it (or "Free"), and whether its screen is live.
- Filters: all machines, reporting, not reporting, not enrolled — each with a
  count.
- Clicking a tile opens a solo view that streams that machine's screen at
  about one frame per second (1280 px wide, JPEG quality 60).
- A machine with no fresh frame shows "No signal" — never a frozen screenshot.
- Visibility is scoped: administrators see the whole lab; teachers see only
  PCs occupied by students in their own running class.
- Each visit to the page is recorded in the audit trail; individual frame
  fetches are not (to avoid thousands of log rows per minute).
- An administrator-only section explains every non-reporting machine (never
  checked in, silent, revoked) with what to do about each, plus the current
  capture settings.
- Live Screens is optional: the rest of the system works with zero clients.

### 6.4 Schedules

- Staff book classes with subject title, teacher, year-section (or whole
  year), purpose, start and end.
- Durations must fall within 30 minutes to 8 hours.
- Double-booking is rejected: the same teacher and section cannot hold
  overlapping bookings (cancelled bookings release their slot).
- A booking can be cancelled; history is kept.

### 6.5 Class Sessions and Attendance

- A teacher starts a class from a booking ("Start class"); only one session
  runs lab-wide at a time.
- Students see the running class on their dashboard and press "Join class".
  Joining is idempotent — pressing twice records once.
- The teacher's roster updates live as students join, without refreshing.
- Ending the class is idempotent and announced to all viewers.
- Attendance history: per-student rate (attended vs. classes held for their
  section), breakdown by subject, and the 25 most recent sessions attended.
  Students with no section assignment see "Not available" rather than a
  fabricated denominator.
- Teachers can only view rosters of sessions they started; administrators see
  all. Session history cannot be edited, and only administrators may delete
  it (it is treated as evidence).

### 6.6 Personal File Drive

- Every student gets a personal drive with folders, rename, delete (folders
  delete recursively with confirmation), single-file download, and
  folder-as-ZIP download.
- Uploads accept up to 10 files at once; in a mixed batch the good files are
  kept and only the rejected ones are reported.
- Safety on every upload: 50 MB per-file cap, an allowlist of 19 extensions
  (documents, images, text, archives), a blocklist of executable extensions
  and MIME types, and content sniffing — the declared filename is never
  trusted. Quota is enforced on every write, including chat attachments.
- List and grid views, search, sorting, breadcrumbs, image previews, and
  per-file details (size, type, location, date).

### 6.7 Group Chat

- Staff create named groups; each group gets an 8-character join code.
- Students join with the code (invalid codes get a generic "not valid"
  response that reveals nothing). Leaving is allowed, except group creators
  cannot abandon their own group.
- Messages up to 2,000 characters, with replies, per-message delete (sender,
  group admin, or staff; messages are otherwise immutable), and system rows
  that announce joins and leaves.
- File attachments up to 10 MB each (5 per message) from a 10-type allowlist
  with the same executable/MIME checks and quota enforcement as the drive.
  Each file downloads through a membership-checked route.
- Unread badges per room (opening a room marks it read), live transcript
  updates, and "X is typing…" indicators that expire after 6 seconds.
- Group roles (admin/member) are scoped to the group and independent of
  system roles. Only the creator can delete a group or change its code
  (changing the code invalidates the old one).

### 6.8 Audit Trail

- Creations, changes (old → new values), and deletions of users, PCs,
  clients, schedules, sessions, sections, files, and groups are recorded with
  who, when, IP address, and user agent. Writes are queued so they never slow
  down staff actions.
- Secrets (passwords, tokens, hashes) are redacted before storage.
- High-volume records (attendance rows, PC sessions, chat messages) are
  deliberately excluded to keep the trail queryable.
- Screen-page visits are logged once per visit. The trail is append-only:
  nothing in the interface edits or deletes it.

### 6.9 Accessibility

- Per-user text size (normal/large/extra-large), high contrast, and
  text-to-speech preferences. Only the owner can view or change them.

## 7. Lab Client Subsystem

Each lab PC runs **lab_client**, a ~15 MB single-file Windows program (Python
frozen with PyInstaller, so lab PCs need no Python installed). It is invisible:
no window, no tray icon, started at every logon by a scheduled task (a task,
not a service, because services cannot see the interactive desktop and would
capture black frames).

The client loops: fetch configuration → capture the desktop → compress →
upload → heartbeat. Every timing value (1-second interval, 1280 px width,
JPEG quality 60, 512 KB frame cap) is handed down by the server, so retuning
the lab means editing `.env`, not touching machines.

Deployment per machine: copy the generated `KobenLabs-Setup` folder, run
`install.cmd` (handles the administrator prompt), enter the server address and
paste the machine's one-time enrolment code. The code is shown once; only its
SHA-256 hash is stored. Re-enrolment kills the old code, revocation wipes the
stored screenshot, and `uninstall.cmd` removes the client.

Client states shown to staff: not enrolled, enrolled but never checked in,
silent (was reporting, stopped), reporting (healthy), revoked.

## 8. System Architecture

```
 Lab PCs (Windows)                Server PC                    Browsers
┌──────────────────┐     ┌────────────────────────┐     ┌──────────────────┐
│ lab_client.exe   │────▶│ PHP app (Laravel)      │◀────│ Staff / students │
│ - capture screen │POST │ - Admin panel /admin   │     │ Livewire pages   │
│ - upload frame   │     │ - Student panel        │     │                  │
│ - heartbeat      │◀────│   /student             │     └──────────────────┘
└──────────────────┘config│ - MySQL database     │
                          │ - Queue worker       │     ┌──────────────────┐
                          │ - Scheduler          │────▶│ Reverb :8080     │
                          │ - Reverb websocket   │     │ live updates     │
                          └────────────────────────┘     └──────────────────┘
```

- **Web application (Laravel):** two Filament panels, API for the client,
  policies for every action, queued jobs, scheduled tasks.
- **Database (MySQL):** 18 tables — users, year-sections, PCs, client
  credentials, PC sessions, schedules, class sessions, attendance, files,
  group chats, messages, memberships, read receipts, accessibility settings,
  audit log, plus framework tables (sessions, cache, jobs).
- **Reverb websocket server:** pushes screen signals, roster changes, chat
  messages, typing indicators, and PC status to browsers live.
- **Queue worker and scheduler:** drain audit writes and mark idle PCs offline
  every 5 minutes.
- **Lab client:** reports screens and heartbeats; configured entirely from
  the server.

## 9. Technology Stack

| Layer | Technology |
|---|---|
| Backend framework | Laravel 13 (PHP 8.4) |
| Admin / student UI | Filament 5, Livewire 4 (real-time components) |
| Styling | Tailwind CSS 4, Vite build |
| Database | MySQL 8 (SQLite in-memory for tests) |
| Real-time | Laravel Reverb (self-hosted websockets) |
| Background work | Database queue, scheduler |
| Testing | Pest 5 (over 420 tests), Laravel Pint (code style) |
| Lab client | Python (mss, Pillow, pywin32), frozen with PyInstaller |
| Dev serving | Laravel Herd (`kobenlabs.test`); LAN sharing via PHP server |

## 10. Database Design (Summary)

Core entities and their relationships:

- **users** belong to a **year_sections** entry; have many files, sessions,
  attendances, group memberships, and audit rows.
- **pcs** have one client credential (**pc_clients**), many occupancy
  sessions (**pc_sessions**), and status/last-seen tracking.
- **schedules** (teacher + section + time window) have many **class_sessions**;
  each session has many **attendances** (one row per student, unique).
- **stored_files** is a single self-referencing table for both files and
  folders (parent/children).
- **group_chats** have members (**group_chat_members** with per-group roles)
  and **group_chat_messages** (text/file/image/system, threaded replies),
  with **group_message_reads** receipts.
- **accessibility_settings** (one row per user) and **audit_logs**
  (polymorphic, append-only) complete the schema.

User roles, PC/client/session/message states, and text sizes are backed PHP
enums shared by validation, UI badges, and policy decisions.

## 11. Security Features

- Password hashing; bearer tokens shown once, stored as SHA-256; revocation
  is immediate and wipes residual screenshots.
- Strict authorization: every panel action resolves a policy; missing
  abilities deny rather than grant.
- Uploads validated by size, extension allowlist, executable blocklists, and
  server-side MIME sniffing; chat downloads re-check membership; no
  path-traversal (server-generated storage paths).
- Teachers see only their own class's PCs and rosters; students never see
  screens, other drives, or other rosters; creators alone delete groups.
- Audit secrets redacted; audit log append-only and staff-read-only.
- Lab-client API uses its own guard (no session/CSRF surface), rejects
  disabled monitoring with 503, and never echoes credentials.

## 12. Testing and Quality

- Over 420 automated tests (feature + unit) covering enrolment, sessions,
  attendance math, quotas, allowlists, chat rules, policies, and Live Screens
  states; suite runs on in-memory SQLite.
- Pint enforces code style on every PHP change.
- Installation, sharing, and troubleshooting are documented step-by-step in
  `docs/installation.md`, with internals in `docs/reference.md` and the
  migration rationale in `docs/migration/`.

## 13. Deployment

- **Development:** Laravel Herd serves `http://kobenlabs.test`; `composer run
  dev` provides queue, scheduler, and Reverb.
- **Laboratory sharing:** PHP's web server bound to the LAN
  (`php -S 0.0.0.0:8000`), firewall rules for app (8000) and Reverb (8080),
  `APP_URL`/`REVERB_HOST` set to the server IP — full steps in
  `docs/installation.md` ("Sharing with the lab").
- **Lab PCs:** `KobenLabs-Setup` folder + per-machine enrolment code.

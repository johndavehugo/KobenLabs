# KobenLabs

A computer-laboratory management system for schools — PCs, classes,
attendance, files, group chat, audit trail, and live screen supervision in one
web app, plus a lightweight background client for each lab PC.

Built with [Laravel](https://laravel.com) 13 and [Filament](https://filamentphp.com) 5.
Replaces a legacy framework-less PHP/PDO application; the migration rationale
lives in [`docs/migration/`](docs/migration/).

## Features

- **Staff panel (`/admin`)** — user accounts, year-sections with quotas,
  PC inventory with online/idle/maintenance presence, class bookings with
  conflict detection, live class sessions with real-time rosters, read-only
  view of every file in the lab, group-chat administration, and **Live
  Screens**: one tile per lab PC, solo screen view, scoped so teachers see
  only their own class.
- **Student panel (`/student`)** — join a running class with one click,
  personal file drive (folders, ZIP download, 50 MB uploads, executable
  blocking, quotas), group chat with join codes, file sharing, read receipts
  and typing indicators, and a personal attendance summary (rate, by subject,
  recent sessions).
- **Lab client** — invisible Windows program (~15 MB, no Python needed on lab
  PCs) capturing and uploading screens about once per second. One-time
  enrolment codes (stored as hashes), per-machine states (reporting, silent,
  never checked in, revoked), server-tuned capture settings. Lives in
  [`clients/lab-client/`](clients/lab-client/), deployed per machine.
- **Behind the scenes** — policy-gated everything (teachers see only their
  own rosters/PCs), self-hosted Reverb websockets for live updates, queued
  audit trail with secret redaction, scheduled idle-PC collection, 420+
  automated tests.

Full feature reference: [`docs/system-overview.md`](docs/system-overview.md).

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.4 |
| UI | Filament 5, Livewire 4, Tailwind CSS 4 (Vite) |
| Database | MySQL 8 (SQLite in-memory for tests) |
| Real-time | Laravel Reverb |
| Testing | Pest 5, Laravel Pint |
| Lab client | Python (PyInstaller single-file `.exe`) |

## Quick Start

**New machine? Follow [`docs/installation.md`](docs/installation.md)** — from
nothing installed to a working system, one step at a time.

```bash
composer install
npm install
cp .env.example .env   # PowerShell: Copy-Item .env.example .env
# fill in DB_* in .env, then:
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force   # creates admin@example.com / password — change it
npm run build
composer run dev
```

Open `http://kobenlabs.test` (Herd). Sharing with lab PCs over the network:
[`docs/installation.md` — Sharing with the lab](docs/installation.md#sharing-with-the-lab).

## Documentation

| Doc | Contents |
|---|---|
| [`docs/system-overview.md`](docs/system-overview.md) | Whole-system reference: actors, features, architecture, stack, security |
| [`docs/installation.md`](docs/installation.md) | Install, lab-PC enrolment, network sharing, troubleshooting |
| [`docs/reference.md`](docs/reference.md) | Every setting explained, Live Screens internals |
| [`docs/migration/README.md`](docs/migration/README.md) | Migration plan and security debt repaid |
| [`docs/migration/legacy-system.md`](docs/migration/legacy-system.md) | What the old system did |
| [`docs/migration/schema-mapping.md`](docs/migration/schema-mapping.md) | Old → new table/column mapping |

## Testing

```bash
php artisan test --compact   # full suite, in-memory SQLite
vendor/bin/pint --dirty      # code style for changed PHP files
```

## License

Open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# Setting up KobenLabs on a new computer

Everything needed to get the app running locally and click through it end to end.
Written against the current state of the repository — versions verified, commands
copied from `composer.json`, and the schema read from the live database.

There is **no seeder and no data import**. The legacy database was never migrated;
the schema was rebuilt from `legacy.sql` and the app is driven by factories and the
UI. Step 6 creates the first admin by hand because that is unavoidable.

---

## 1. What you need

| Tool | Version | Notes |
| --- | --- | --- |
| PHP | **8.3+** (developed on 8.4.25) | `composer.json` requires `^8.3` |
| Composer | 2.x | |
| Node.js | 20+ | Vite 8 needs a current LTS |
| npm | ships with Node | |
| MySQL or MariaDB | 8.0 / 10.6+ | The default dev database is MySQL |
| Laravel Herd | latest | Optional. Gives you `kobenlabs.test` with no web-server config |

### PHP extensions

All of these must be enabled. Two are easy to miss and the app breaks without them:

```bash
php -m
```

Required: `pdo_mysql`, `pdo_sqlite`, **`fileinfo`**, `mbstring`, `openssl`, `curl`,
`dom`, `xml`, `intl`, `gd`, `zip`.

- **`fileinfo`** — `FileService` sniffs upload contents with `finfo` to reject
  executables. Without it, `finfo` is unavailable and that check silently does
  nothing.
- **`pdo_sqlite`** — the test suite runs on in-memory SQLite (`phpunit.xml`), so you
  can run the tests without MySQL at all.

If you use Herd, check the extension list in Herd's settings. If you use a plain PHP
install, enable them in your `php.ini`.

---

## 2. Get the code

```bash
git clone <repository-url> kobenlabs
cd kobenlabs
```

The repository is `C:\Users\JD\Herd\KobenLabs` on the machine that was developed on.
If you are moving to a new computer, push the branch first — see step 11.

---

## 3. Create the database

MySQL is used by default (`DB_CONNECTION=mysql`, database `koben_lab`, user `root`).

```sql
CREATE DATABASE koben_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The dev credentials are `root` with an empty password, which is what `.env.example`
assumes. If yours differ, change `DB_USERNAME` / `DB_PASSWORD` in `.env` in step 4.

---

## 4. Install and configure

Run the built-in setup script. It is idempotent enough for a fresh machine and does
everything below in one go:

```bash
composer run setup
```

Which is exactly:

```bash
composer install
cp .env.example .env          # skipped if .env already exists
php artisan key:generate
php artisan migrate --force
npm install --ignore-scripts
npm run build
```

### Check the environment afterwards

`.env` should look like this. `APP_URL` must match how Herd serves the project or
Filament's asset URLs and redirects break:

```dotenv
APP_NAME=KobenLabs
APP_URL=http://kobenlabs.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=koben_lab
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local

REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Those non-default drivers are deliberate:

- `SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` are `database` so a fresh
  clone needs no extra services.
- `BROADCAST_CONNECTION=reverb` points at a **self-hosted websocket server** rather
  than a hosted one, because this is a single-school lab. Generate real credentials
  with `php artisan reverb:install` — if the `REVERB_*` values above are empty, that
  command has not been run yet.

### If `composer run setup` fails

Run the steps by hand — the script stops at the first failure:

```bash
composer install
copy .env.example .env        # PowerShell; `cp` in bash
php artisan key:generate
php artisan migrate
npm install --ignore-scripts
npm run build
```

---

## 5. Serve the app

### With Herd (recommended)

Nothing to do. Herd serves the project at:

```
http://kobenlabs.test
```

`APP_URL` in `.env` is already set to that.

### Without Herd

```bash
php artisan serve --port=8000
```

Then set `APP_URL=http://localhost:8000` in `.env` and clear the config cache:

```bash
php artisan config:clear
```

---

## 6. Create the first admin

Neither panel has registration enabled, and there is no seeder, so the first account
has to be made directly. Without it you cannot sign in at all.

```bash
php artisan tinker
```

Then, at the `>>` prompt:

```php
App\Models\User::create([
    'username' => 'admin',
    'email' => 'admin@example.com',
    'first_name' => 'Lab',
    'last_name' => 'Administrator',
    'password' => 'password',
    'role' => App\Enums\UserRole::Admin,
    'status' => App\Enums\UserStatus::Active,
    'email_verified_at' => now(),
]);
```

Sign in at `http://kobenlabs.test/admin` with `admin` / `password`. Change the
password from your profile afterwards.

Notes on the fields:

- `username` and `email` are both unique; `student_id` is unique but must be `null`
  for staff.
- `year_section_id` is nullable and should stay `null` for staff — only students need one.
- `storage_quota_mb` defaults to 500. Admins get `config('lab.storage.admin_quota_mb')`
  (5 GB) when created through the factory, but here the column default applies. Either is fine.

### One-liner alternative

```bash
php artisan tinker --execute 'App\Models\User::create(["username"=>"admin","email"=>"admin@example.com","first_name"=>"Lab","last_name"=>"Administrator","password"=>"password","role"=>App\Enums\UserRole::Admin,"status"=>App\Enums\UserStatus::Active,"email_verified_at"=>now()]);'
```

---

## 7. Background processes

```bash
composer run dev
```

This runs four things at once via `concurrently`:

| Process | Why it is needed |
| --- | --- |
| `php artisan schedule:work` | Runs `pcs:mark-stale-offline` every five minutes |
| `php artisan queue:listen` | Drains the database queue, including audit-log writes |
| `php artisan reverb:start` | The websocket server on port 8080 |
| `npm run dev` | Vite, for hot reloading of CSS/JS |

It deliberately does **not** start a web server — Herd serves the app.

**If port 8080 is already taken**, something else on the machine holds it. Change
`REVERB_PORT` in `.env`, and remember the Vite build reads it at build time, so re-run
`npm run build` after changing it.

If you only want to click through the UI, `composer run dev` is optional. Without it
you lose the PC stale sweep, queued audit writes and CSS hot reload — but nothing
breaks, because no browser subscribes to a websocket channel yet (see section 12).

---

## 8. Verify the install

```bash
php artisan test --compact
```

Expected: **339 tests, 1089 assertions, all passing.** The suite uses in-memory SQLite
and touches neither your dev database nor your uploaded files.

```bash
vendor/bin/pint --test
```

Expected: `{"tool":"pint","result":"passed"}`.

```bash
php artisan route:list --path=student
php artisan route:list --path=admin
```

You should see the student panel pages (`/student`, `/student/my-drive`,
`/student/group-chat`, `/student/messages`) and the admin resources.

---

## 9. Walkthrough: getting data in without seeders

There is no data yet, so build a small world by hand first. All of this is done in
the UI on purpose — it doubles as a smoke test.

**As admin at `/admin`:**

1. **Years and Sections → New year and section.** Create `BSIT`, section `2A`. Leave
   **Active** ticked — an inactive year/section is filtered out of every select, so
   you will not be able to pick it for a schedule or a student afterwards.
2. **Users → New user.** Create a teacher (`koben.teacher`, role *Teacher*) and a
   student (`koben.student`, role *Student*, student ID `2026-001`, year/section
   *BSIT - 2A*). Student ID and year/section only appear for the student role.
3. **PCs → New PC.** Add one, e.g. `LAB-PC-01`. It starts offline.
4. **Schedules → New schedule.** Subject *Data Structures*, teacher
   `koben.teacher`, year/section *BSIT - 2A*, starting now and ending in a couple of
   hours. `ScheduleBookingRules` will refuse times outside the configured bounds
   (30 minutes to 8 hours by default — see `config/lab.php` under `schedules`).
5. **Class Sessions → Start class.** Start the schedule above. It becomes *running*.
6. **Messages → Compose.** Send something to the student, and try *Broadcast to
   every student*.
7. **Group chats → New group chat.** Create a group. Note the generated join code —
   the creator is enrolled as a group administrator. Add the student as a member.

**As the student, in a private browser window** (`http://kobenlabs.test/student`):

8. Sign in as `koben.student`. The student panel shows only *My Drive*, *Class*,
   *Group Chat*, *Messages* and *My Attendance* — the admin resources are not
   reachable, and the URL returns 403.
9. **Class** — a *Join class* button appears for the running session. Press it, then
   re-check the staff **Class Sessions** roster: the student is listed.
10. **My Drive** — create a folder, upload a `.txt` or `.png`. The Type column shows
    the *sniffed* MIME type. Try uploading a `.exe` and watch it be rejected. Switch
    to grid view, rename a file, download a folder as a ZIP. Open
    `/admin/stored-files` in the admin window: the file is visible to staff.
11. **Group Chat** — send a message, attach a file, download it back.
12. **Messages** — the message from step 6 appears, opening it clears the unread
    count, and the attachment downloads.
13. **My Attendance** — the rate reflects step 9: attended 1 of 1 completed class.

Things that will *not* work yet, so you do not think they are broken:

- PC status will not change on its own. There is no PC auto-detect on login in this
  app; a PC is marked online by calling `PcPresenceService` or running the stale
  sweep.
- Nothing updates in real time in the browser. The server publishes to Reverb and
  authorizes every channel, but no page subscribes yet, so press **Refresh**.
- Message attachments and group chat file uploads are counted against the **sender's**
  quota, which is the fix for the legacy bug; there is no per-recipient quota.
- Audit entries appear a moment after the change, because they are queued. Run
  `composer run dev` (or `php artisan queue:work`) or they will sit unprocessed.

---

## 10. Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Route [login] not defined` | A guest hit a plain web route. Fixed in `bootstrap/app.php`, but if you are on an old checkout, pull | `git pull` |
| Blank page, no styles | Vite has not built or is not running | `npm run build`, or run `composer run dev` |
| `Unable to locate file in Vite manifest` | Stale build | `npm run build` |
| Everything 404s | `APP_URL` does not match how you are serving it | Set `APP_URL`, then `php artisan config:clear` |
| `SQLSTATE[HY000] [1045]` | Wrong DB credentials | Check `DB_*` in `.env` |
| `Base table or view not found` | Migrations not run | `php artisan migrate` |
| Uploads always rejected as executable | `fileinfo` missing, so `finfo` cannot sniff | Enable `fileinfo` |
| `Class "User" not found` in a test | Missing `use App\Models\User;` at the top of the test file | Add the import |
| Cannot sign in at all | No admin account exists yet | Step 6 |
| Ports already in use (`composer run dev`) | Another `composer run dev` is still running | Stop the other one |

### Clearing up state

```bash
php artisan optimize:clear     # config, route, view, event, cache
php artisan migrate:fresh      # drops all data and re-migrates — MySQL only
php artisan storage:link       # only if you add public disk assets later
```

`migrate:fresh` is destructive. The test suite never touches your dev database.

---

## 11. Moving this project to a new computer

If you are relocating an existing working copy rather than starting fresh:

1. **Push the code.** `git status` should be clean; commit anything outstanding, then
   push the branch.
2. **Do not copy `.env`.** It holds `APP_KEY`. Copy it if you want the same encrypted
   values, but the file is in `.gitignore` and will not travel with the repository.
   Generate a fresh key on the new machine — the only consequence is that existing
   sessions and password-reset tokens are invalidated.
3. **Do not copy `vendor/`, `node_modules/`, or `public/build/`.** Reinstall.
4. **The database has no seed.** If you want the accounts you created by hand, either
   recreate them with step 6 or take a `mysqldump`:
   ```bash
   mysqldump -u root -p koben_lab > koben_lab.sql
   mysql -u root -p koben_lab < koben_lab.sql
   ```
5. **Uploaded files live in `storage/app/private`.** They are not in git. Copy the
   directory across, or start with an empty drive — every upload is recreated through
   the UI.
6. Run steps 4 (setup), 6 (admin) and 8 (verify).

---

## 12. What is wired up, and what is not

Honest status, so a missing feature reads as "not built yet" rather than "broken".

**Working:**

- Two panels with strict authorisation: staff on `/admin`, students on `/student`.
- Users, year/sections, PCs, schedules, class sessions with a live attendance roster.
- Personal file drive with one upload policy and one quota path, plus a read-only
  staff view. Rename, folder-as-ZIP, image previews, list/grid toggle, search and
  sorting.
- Group chat on both panels, with members managed by staff and join codes for students.
- Direct messages: student inbox, staff compose, broadcast to all students, and
  policy-gated attachment downloads.
- Student attendance summary with a per-subject breakdown.
- Audit trail: config-driven model list, queued writes, redacted passwords.
- Broadcasting: self-hosted Reverb, three private channels, every channel authorized
  by a policy. Reverb is installed and verified listening.

**Not built yet:**

- **Browser websocket listeners.** The server publishes and authorizes every event,
  and Reverb runs, but no page subscribes to a channel — so the UI still needs
  **Refresh**. This is the remaining work in Phase 5, not a configuration gap.
- **Whole-folder upload.** Filament 5 has no `webkitdirectory` helper, and browsers do
  not send relative paths in the multipart filename, so rebuilding the tree on the
  server would need custom client JavaScript.
- **PC auto-detect on login.** No IP matching; a PC is marked online through
  `PcPresenceService`.
- **No seeders, no data import, no legacy database migration.**
- **`pest-plugin-livewire` is not installed.** `livewire()` in `tests/Pest.php` is a
  local helper. Do not assume Livewire testing helpers you have seen elsewhere exist.

Further reading: `docs/migration/README.md` covers the migration plan, the security
debt being repaid, and why each decision went the way it did.
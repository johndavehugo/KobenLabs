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
| Laravel Herd | latest | Gives you `kobenlabs.test` with no web-server config. Binds loopback only, so it **cannot** serve a lab — see §5b |

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

#### Do not point the Lab Client at `artisan serve`

On Windows, `php artisan serve` **drops `TEMP` and `TMP` from the environment** it hands
to the PHP server. They are not in Laravel's passthrough list, and everything absent from
that list is set to `false`, which unsets it.

PHP then has no temporary directory, so it cannot create the file it buffers a POST body
into. Every frame upload is discarded before Laravel sees it, and the client is told
`200 OK` with this in the response body:

```
Warning: PHP Request Startup: Unable to create temporary file
Warning: PHP Request Startup: POST data can't be buffered; all data discarded
```

The symptom is a machine that looks installed and reports as Silent forever, with the
client log filling with `frame rejected with HTTP 200`. Nothing else goes wrong, which is
what makes it hard to read — the server is healthy, the code is valid, and the frames are
being captured.

**Use Herd for anything the Lab Client talks to.** `http://kobenlabs.test` handles frame
uploads correctly. `artisan serve` is fine for *viewing* Live Screens in a browser, since
those requests are GETs and never need a buffer.

---

## 5b. Why the lab server is set up this way

The steps are in [`installation.md`](installation.md#sharing-with-the-lab) — that file owns
the "how". This section only covers the "why", which is the part that is hard to re-derive
when something breaks.

**Why Apache at all?** Herd binds `127.0.0.1:80` — loopback only. It is a development
server, and no other machine can reach it by any address. `herd share` is the only supported
way to reach a Herd site from elsewhere, and that publishes to the internet, which is not
what a lab wants for student data.

**Why not move the project into `htdocs`?** It does not help. Apache loads
`C:\xampp\php\php8apache2_4.dll` wherever the site lives, and that is PHP **8.2.12**. The app
needs **8.4.1** — Composer's generated `vendor/composer/platform_check.php` rejects anything
older on every request, with a 500 before the login page renders. The PHP version is a
property of the server, not of the folder.

**Why not update XAMPP?** Its newest release still ships PHP 8.2.12, so there is nothing
newer to install. And PHP stopped publishing **thread-safe** Windows builds, which an Apache
module requires — `windows.php.net` offers only `nts`. So a newer PHP cannot be dropped in as
a module either. Both routes are closed.

**Why FastCGI?** It runs PHP as a separate process, where thread safety does not apply, so
Herd's existing **NTS 8.4.25** works unchanged. Herd already does exactly this for
`kobenlabs.test`, using `php-cgi` on port 9084 — the lab setup just points Apache at the same
kind of process on port 9000.

**Why `ProxyFCGIBackendType GENERIC`?** Apache assumes a FastCGI backend is PHP-FPM.
`php-cgi` is not, and Apache then misreads `SCRIPT_FILENAME` and fails every single request:

```
URI cannot be parsed: fcgi://127.0.0.1:9000C:/Users/.../index.php
```

This is the single most common reason this setup does not work, and the error names neither
PHP nor FastCGI, so it reads like an Apache bug rather than a missing directive. It needs
Apache **2.4.26+**; older versions reject the directive outright.

**Why port 8082 rather than 80?** Port 80 is shared with whatever else the machine serves. A
separate port means a mistake in this config cannot take those sites down.

**Why `DocumentRoot` and not `Alias`?** An alias leaves `SCRIPT_FILENAME` ambiguous on
Windows, because the path Apache derives has no leading slash and is concatenated rather
than treated as a path component.

**Why `httpd -t` before restarting?** A malformed line anywhere in an included file stops
the whole server from starting, taking every other site with it. The syntax check catches that
before anything restarts, which is why it is a separate step rather than part of the restart.

**Why `npm run build` and never `npm run dev`?** A dev server writes `public/hot` containing
a loopback address such as `http://[::1]:5173`. Lab PCs then try to load the app's CSS and
JavaScript **from themselves**, get nothing, and render an unstyled page with no working
buttons — with no error anywhere. It reads as a broken app rather than a configuration
mistake. `composer run dev` deliberately does not start Vite for this reason; `composer run
dev:vite` does, and must not be used on the lab server.

**Why does `APP_URL` matter?** The app never reads it for logic. Filament builds asset URLs
and redirects from it, so left as `kobenlabs.test` every student is redirected to a hostname
their machine cannot resolve.

**Why does `REVERB_HOST` matter?** Left as `localhost`, every browser looks for the Reverb
websocket **on its own machine**. Only the server runs one, so live updates and typing
indicators work for you and silently fail for everyone else — the most misleading failure in
the whole setup, because it works when you test it.

**Why do the upload limits need raising separately?** PHP rejects files over 2 MB by default
while the app allows 50 MB (`LAB_UPLOAD_MAX_KB`). Fixing the PHP *version* does not fix this,
so it is a genuinely separate step. `post_max_size` must exceed `upload_max_filesize` to
leave room for the multipart headers.

**Why does `php-cgi` need starting at boot?** Apache as a service restarts itself;
`php-cgi` does not. Without it the site returns `502 Bad Gateway` after a reboot while
everything else on the machine still works, which points at Apache rather than at the missing
process.

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

This compiles the assets, then runs three things at once via `concurrently`:

| Process | Why it is needed |
| --- | --- |
| `npm run build` | Compiles CSS/JS. Run first, so the assets exist before anything serves them |
| `php artisan schedule:work` | Runs `pcs:mark-stale-offline` every five minutes |
| `php artisan queue:listen` | Drains the database queue, including audit-log writes |
| `php artisan reverb:start` | The websocket server on port 8080 |

It deliberately does **not** start a web server — Herd serves the app — and does **not**
start Vite. Vite writes a `public/hot` file pointing at a loopback address, which is correct
for one developer on their own machine and leaves every other machine with no CSS or JS at
all.

For hot reloading while working on the app, use `composer run dev:vite` instead. Never on a
lab server — see §5b.

### `composer run serve`

```bash
composer run serve
```

Starts `php-cgi` on `127.0.0.1:9000`, which Apache needs on a lab server (§5b). Only
required there; on a dev machine Herd already runs its own `php-cgi`.

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

Expected: **439 tests, 1376 assertions, all passing.** The suite uses in-memory SQLite
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
`/student/group-chat`) and the admin resources.

---

## 8b. Live Screens (optional)

Live Screens is the **Lab Client** — a background process on each lab PC that reports
its screen to a staff page, so a teacher can see what a class is working on. It is off
the critical path: everything above works without it, and the app runs fine with zero
clients enrolled.

### What it looks like

A **Live Screens** page on the admin panel, showing one tile per machine the signed-in
staff member is entitled to watch. A teacher sees only the PCs their own class is
using; an administrator sees every PC in the lab. Tiles show a machine name, the
student sitting at it, and whether a current frame exists.

### Enrol a machine

You do not need the command line for any of this.

1. Register the PC if it is not already there: **Computers** → **New computer**
2. On that PC's row, click **Enrol client**
3. A one-time code appears — copy it
4. Run the Lab Client installer on that machine and paste the code when asked

The code is shown once and only a SHA-256 hash is stored, so if it is lost, click
**Enrol client** again for a fresh one. Clicking it on an already-enrolled machine
warns you first, because the old code stops working and the machine drops off the grid
until the new one is installed.

The **Lab Client** column on the Computers list shows each machine's state:

| State | Meaning |
| --- | --- |
| **Not enrolled** | No client installed yet |
| **Never checked in** | Installed, but has never reached the server |
| **Silent** | Was reporting and has stopped |
| **Reporting** | Working |
| **Revoked** | Credential withdrawn — enrol again to restore |

**Never checked in** and **Silent** are deliberately different. The first means the
code or server URL is wrong; the second means the machine needs looking at.

**Live Screens** shows the same information grouped by problem, with what to do about
each, so you can find the machines that need attention without reading the table.

### For scripts and fleets

The admin buttons call the same code as `pcs:enrol-client`, `pcs:revoke-client` and
`pcs:monitoring-status`, so those commands still work for automating a whole lab.
They are documented here rather than as the primary route because most people
enrolling a handful of machines should not have to know they exist.

### Install the client

Copy `clients/lab-client/` to the lab PC, then **double-click `install.cmd`**.

It asks Windows for permission once, then asks you two questions:

1. **Server address** — the server PC's IP, e.g. `http://192.168.1.10`. You can paste
   the bare address and it assumes `http://`.
2. **The one-time code** — from the Enrol client button.

Then it installs, starts the client, waits, and confirms the machine is reporting.
It refuses to change anything if the code is wrong, so a typo cannot leave you with a
half-installed machine.

**What it installs:** on the Python path it installs `mss`, `Pillow` and `pywin32`
from the pinned `requirements.txt`, skipping the download if they are already present.
It uses the **frozen `.exe`** path there is nothing to install at all — Python and every
library are inside the one file.

The same file works on every machine in the lab, so you can keep one copy and carry it
round. Re-running it on a machine that already has the client with `-Force` replaces
the program but **keeps that machine's code**, so an update never takes a lab PC off
the grid.

**If you want no Python on the lab machines**, build the standalone program once on
any Windows machine with Python 3.11+:

```powershell
cd clients\lab-client
.\build.ps1
```

That produces `dist\lab_client.exe` (about 15 MB, Python included). Copy it to the lab
PC alongside `install.cmd` and `install-client.ps1`, then:

```powershell
.\install-client.ps1 -Executable .\lab_client.exe
```

This registers a **scheduled task** that starts at every logon and runs windowless — no
console, no tray icon, nothing visible to whoever is using the machine.

> It is a scheduled task rather than a Windows Service, deliberately. A Service runs
> in session 0, which has no interactive desktop, so screen capture there returns a
> black frame forever — the client would look healthy while showing nothing. A task
> runs in the user's session, which is the desktop being watched.

Check it is reporting on the **Computers** list — the **Lab Client** column should say
**Reporting**. Or look on the **Live Screens** page, which groups the machines needing
attention and says what to do about each.

### Tuning

Every capture setting is in `.env` under `LAB_MONITORING_*` and is read by the server,
which hands the effective values to each client on every request. Change
`LAB_MONITORING_INTERVAL` to `2` and every enrolled machine slows to half rate without
being touched. Setting `LAB_MONITORING_ENABLED=false` turns the whole feature off
while leaving the clients installed.

### Two things to know

- **Serve it over HTTPS.** Screen content sent over plain HTTP is readable by anyone on
  the network who captures packets. Laravel Herd gives you local HTTPS automatically;
  a real deployment needs a certificate.
- **Revoking is separate from uninstalling.** After removing the client from a machine,
  run `php artisan pcs:revoke-client "LAB-01"` so the token stops working. Uninstalling
  does not invalidate a credential that was copied elsewhere.

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
6. **Group Chat → New Group.** Create a group. It gets a join code, which is shown on the
   group with a copy button beside it — that is what you read out to the class, so check it
   copies. The creator is enrolled as a group administrator.

**As the student, in a private browser window** (`http://kobenlabs.test/student`):

7. Sign in as `koben.student`. The student panel shows only *My Drive*, *Class*,
   *Group Chat* and *My Attendance* — the admin resources are not
   reachable, and the URL returns 403.
8. **Class** — a *Join class* button appears for the running session. Press it, then
   re-check the staff **Class Sessions** roster: the student is listed.
9. **My Drive** — create a folder, upload a `.txt` or `.png`. The Type column shows
   the *sniffed* MIME type. Try uploading a `.exe` and watch it be rejected. Switch
   to grid view, rename a file, download a folder as a ZIP. Open
   `/admin/stored-files` in the admin window: the file is visible to staff.
10. **Group Chat** — press *Join with code* and enter the code from step 6. The group
     appears in the list; open it and send a message, attach a file, download it back.
     Rename and Leave sit on the open group, not in the page header. The sidebar shows an
     unread count per group and clears it when you open that group.
11. **My Attendance** — the rate reflects step 8: attended 1 of 1 completed class.

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
- Group chat on both panels, with members managed by staff and join codes for students,
  and policy-gated file downloads.
- Student attendance summary with a per-subject breakdown.
- Audit trail: config-driven model list, queued writes, redacted passwords and
  credential digests.
- Broadcasting: self-hosted Reverb, four private channels, every channel authorized
  by a policy. Browser listeners are wired, so PC status, class sessions and chat
  update without a manual **Refresh**.
- Live Screens: per-PC screen reporting from the Lab Client, scoped so a teacher sees
  only their own class and an administrator sees the whole lab. Section 8b.

**Not built yet:**

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

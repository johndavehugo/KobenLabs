# Installing KobenLabs

From a machine with nothing installed to a working system. Follow top to bottom.

**New here? This is the whole guide.** If you need a setting explained rather than a step
followed, see [`reference.md`](reference.md).

---

## 1. Install these four things

| What | Version | Link |
|---|---|---|
| PHP | 8.3+ | [windows.php.net/downloads](https://windows.php.net/downloads) |
| Composer | 2.x | [getcomposer.org](https://getcomposer.org/Composer-Setup.exe) |
| Node.js | 20+ | [nodejs.org](https://nodejs.org) |
| MySQL | 8.0+ | [dev.mysql.com/downloads](https://dev.mysql.com/downloads/mysql/) |

[Install Laravel Herd](https://herd.laravel.com) instead and it bundles PHP, Composer and a
web server for you.

Check it worked — each should print a version:

```bash
php -v && composer -V && node -v && npm -v
```

## 2. Create the database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE koben_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'koben'@'localhost' IDENTIFIED BY 'pick-a-real-password';
GRANT ALL PRIVILEGES ON koben_lab.* TO 'koben'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Remember that password — step 3 needs it.

## 3. Get the code and fill in the blanks

```bash
git clone <your-repository-url> KobenLabs
cd KobenLabs
```

A clone is missing four things, all generated. Create them:

```bash
composer install          # -> vendor/
npm install               # -> node_modules/
cp .env.example .env      # -> .env    (PowerShell: Copy-Item .env.example .env)
```

**Then edit `.env`** — four values are blank and the app will not run without them:

```dotenv
DB_DATABASE=koben_lab
DB_USERNAME=koben
DB_PASSWORD=the-password-from-step-2
```

Set `APP_NAME` to your school's name if you like. Leave `APP_KEY` and the `REVERB_*` keys
blank; the next step fills them.

## 4. Five commands, in this order

```bash
php artisan key:generate      # APP_KEY
php artisan reverb:install    # REVERB_APP_ID / KEY / SECRET
php artisan migrate --force   # builds all 23 tables
php artisan db:seed --force   # creates your admin login
npm run build                 # compiles CSS and JavaScript
```

## 5. Sign in

The seeder creates one administrator:

```
http://kobenlabs.test

email     :  admin@example.com
password  :  password
```

**Change that password before you use KobenLabs for anything real.**

To choose your own before seeding, add these to `.env` and re-run
`php artisan db:seed --force`:

```dotenv
SEED_ADMIN_EMAIL=you@school.edu
SEED_ADMIN_PASSWORD=something-long
SEED_ADMIN_FIRST_NAME=Your
SEED_ADMIN_LAST_NAME=Name
```

> The old seeder created a *student* called "Test User", so there was no way to log in at
> all. It now creates an administrator, and this is the only account to start with.

## 6. Run it

```bash
composer run dev
```

That compiles your CSS and JavaScript, then starts three things at once — queue worker,
scheduler, and Reverb.
**Leave the window open.** Without them, uploads never save and screens never update live.

Open `http://kobenlabs.test` (Herd) or `http://localhost:8000` (without Herd).

Working on the app and want live reload? Use `composer run dev:vite` instead. Not for a
lab server — see [Sharing with the lab](#sharing-with-the-lab).

---

## Adding a lab computer

**Build the Lab Client once**, on a machine with Python:

```bash
cd clients\lab-client
.\build.ps1
```

That produces `dist\lab_client.exe` and a `KobenLabs-Setup` folder.

**Then, for each computer:**

1. Copy `KobenLabs-Setup` onto it (USB stick is fine)
2. In KobenLabs: **Computers → New computer** — give it a name like `LAB-01` and its fixed
   IP
3. On that computer's row: **Enrol client** → copy the code (shown **once**)
4. On the computer: double-click **`install.cmd`**, accept the admin prompt, enter the
   server address — `http://kobenlabs.test` on your dev machine, or
   `http://192.168.254.103:8082` on the lab server — then paste the code

**Nothing is installed on the computer** — no Python, no libraries. That's why we ship a
built `.exe` rather than a script.

Check it worked: the row says **live**, and the screen appears under **Live Screens**.

To remove it later: `uninstall.cmd` in the same folder.

---

## Sharing with the lab

**On the PC the lab's browsers talk to** — not the one you develop on.

Herd only listens on `127.0.0.1`, your own machine, so lab PCs cannot reach it. That PC
needs Apache instead. Replace `192.168.254.103` below with this machine's IP (`ipconfig`).

### 1. Find Herd's php-cgi

```bash
herd which-php kobenlabs.test
```

`php-cgi.exe` is in that same folder. `"<folder>\php-cgi.exe" -v` must report **8.4.1+**.

### 2. Write the Apache config

New file `C:\xampp\apache\conf\extra\httpd-kobenlabs.conf`:

```apache
Listen 8082

LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
ProxyFCGIBackendType GENERIC

<VirtualHost *:8082>
    DocumentRoot "C:/path/to/KobenLabs/public"

    <Directory "C:/path/to/KobenLabs/public">
        AllowOverride All
        Require all granted

        <FilesMatch "\.php$">
            SetHandler "proxy:fcgi://127.0.0.1:9000"
        </FilesMatch>
    </Directory>
</VirtualHost>
```

Change `DocumentRoot` to this machine's path.

### 3. Include it

Append to `C:\xampp\apache\conf\httpd.conf`:

```apache
Include conf/extra/httpd-kobenlabs.conf
```

### 4. Check the syntax, then restart

```bash
"C:\xampp\apache\bin\httpd.exe" -t
```

Must print `Syntax OK`. **Do not restart until it does** — one bad line stops the whole
server, including every other site on the machine.

Restart Apache, then check the other sites still load.

### 5. Raise the upload limits

In `C:\Users\you\.config\herd\bin\php84\php.ini`:

```ini
upload_max_filesize = 50M
post_max_size = 52M
```

PHP defaults to 2M; the app allows 50M.

### 6. Start php-cgi

```bash
composer run serve
```

Leave it running.

### 7. `.env`

```dotenv
APP_URL=http://192.168.254.103:8082
REVERB_HOST=192.168.254.103
```

```bash
php artisan config:clear
```

### 8. Firewall — Administrator PowerShell

```powershell
New-NetFirewallRule -DisplayName "KobenLabs HTTP" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8082 -Profile Any
New-NetFirewallRule -DisplayName "KobenLabs Reverb" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8080 -Profile Any
```

### 9. Build the assets

```bash
npm run build
```

**Never `npm run dev` on this machine** — it writes a `public/hot` file pointing at your own
machine, and lab PCs then load no CSS or JS, with no error message. `composer run dev` is
fine; `composer run dev:vite` is not.

### 10. Start on boot

XAMPP Control Panel — tick **Apache** under *Service*. Add a startup task running
`composer run serve` in the project folder.

### Check it works

From a lab PC, open `http://192.168.254.103:8082`:

1. Log in
2. Create a group, note the join code
3. Sign in as a student, join with that code
4. Send a message
5. **Attach a file, send it, download it back** — proves the upload limits
6. **Two browsers: type in one, "is typing…" in the other** — proves `REVERB_HOST` + firewall

### To undo it

Remove the `Include` line, delete `httpd-kobenlabs.conf`, restart Apache. Nothing in the
project depends on Apache.

**Why any of this is necessary:** see
[`reference.md` section 5b](reference.md#5b-why-the-lab-server-is-set-up-this-way).

---

## If something goes wrong

| Symptom | Fix |
|---|---|
| "Application key not set" | `php artisan key:generate` |
| "connection refused" | MySQL not running, or `.env` details are wrong |
| "Base table or view not found" | `php artisan migrate --force` |
| Page has no styling | `npm run build` |
| Styling works on my PC but not my phone | Run `npm run build`. If you used `composer run dev:vite`, switch back to `composer run dev` — it leaves a `public/hot` file that points assets at your own machine |
| Screens say "no signal" | Re-run `install.cmd` on that computer |
| Reports live but shows no image | You entered an **IP** as the server address — use `http://kobenlabs.test` |
| Chat and screens don't update live | Reverb isn't running — check `composer run dev` |
| Uploads never save | Queue worker isn't running |
| "Vite manifest" error | `npm run build` |
| Lab PC gets a blank unstyled page | A stale `public/hot` is pointing assets at your own machine. Delete it, then `npm run build` |
| Lab PC can't connect at all | Apache isn't listening, or the firewall rule is missing |
| Live updates work for me but not the class | `REVERB_HOST` is `localhost`. Set it to the server's IP |
| `502 Bad Gateway` after a reboot | `composer run serve` isn't starting with Windows — see [reference.md](reference.md#5b-serving-on-the-lab-server) |

Logs: `storage/logs/laravel.log`.

---

## Two things that catch people out

**Use the site name, not the IP, as the server address — unless you are on the lab server.**
`http://192.168.1.10` does not serve the app under Herd; it matches on hostname and an IP
gets a placeholder page. On your own machine, use `http://kobenlabs.test`. It also survives
the server's IP changing.

On the **lab server** the opposite is true: `kobenlabs.test` cannot work, because Herd only
listens on `127.0.0.1` — your own machine. Lab PCs must reach the app by IP. See
[Sharing with the lab](#sharing-with-the-lab) below.

**Don't point the Lab Client at `php artisan serve`.** It works for viewing, but it drops
`TEMP` and `TMP` from PHP's environment, so PHP has nowhere to buffer a POST body. Every
screen frame is silently discarded while the machine still reports as healthy. Use Herd on
your machine; on the lab server use Apache, for the same reason.

---

## Not in the repository, and why

All created by the commands above. Nothing important is missing.

| Missing | Created by | Why it's excluded |
|---|---|---|
| `.env` | step 3 | Holds passwords and secret keys |
| `vendor/` | `composer install` | `composer.lock` pins the exact versions |
| `node_modules/` | `npm install` | `package-lock.json` pins them |
| `public/build/` | `npm run build` | Compiled output; the source is committed |
| `clients/lab-client/dist` | `build.ps1` | A 15 MB binary, rebuildable from committed source |
| `clients/lab-client/.venv` | `build.ps1` | Local Python environment |

Committing a build output means it can drift from the source that produced it, and every
machine ends up a rebuild away from a silent mismatch.

---

## Everyday commands

```bash
php artisan test          # run the tests
vendor\bin\pint           # fix code style
php artisan config:clear  # after editing .env
composer run dev          # queue, scheduler and Reverb
composer run dev:vite     # the above, plus hot reload - dev machines only
composer run serve        # php-cgi on port 9000 - lab server only
```
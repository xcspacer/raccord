# Raccord

Raccord is a white-label field-operations platform for fibre-optic (FTTH) contractors. Each customer gets their own
installation (own server, database and files); the company identity (name, logo, contact details) is set on the
platform settings page, never in the code.

## What it does

- **Planning** — work sites and crews by day or by week, map, capacity, absences, weekly timesheets (Excel).
- **Daily clock-in** — start and end of the day with GPS, offline-tolerant.
- **Technician space** — vehicle inspections (photos, damages, AI comparison), equipment held, expense reports (PDF).
- **Equipment** — inventory lines, movements, handover certificates, label reading by AI.
- **Field audits** — box-by-box audits on phones, working offline (photos, statuses, positions, rechecks of box and
  drawer plans), synthesis and deliverables (PDF, Excel, ZIP).
- **Design office workflow** — optional review process per PM: submissions, review queue with deadlines, occurrences,
  repairs, reserves, closure and an official versioned dossier.
- **Client portal** — the end client follows progress, photos, PDFs, reserves and the official dossier.
- **Media library**, **billing timesheets**, **OTDR acceptance** (SOR files to operator deliverables, in the browser)
  and the **fibre colour code** tool.

Every module is a switch on the platform settings page; access per role is set in « Gérer les accès ». The interface
is available in English (UK), French, Spanish, Ukrainian and Portuguese.

## Stack

Laravel 13 · PHP 8.5 · Livewire 4 (CSP-safe Alpine) · MySQL 8.4 · Vite · TypeScript for the OTDR engine.
Development runs in Docker through Laravel Sail (application service `raccord`, database service `mysql`).

## Local setup (from scratch)

Requirements: Docker and Git.

```bash
git clone git@github.com:xcspacer/raccord.git && cd raccord
cp .env.example .env

# Install PHP dependencies once, without PHP on the host
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/app" -w /app \
  composer:2 install --ignore-platform-reqs

vendor/bin/sail up -d
vendor/bin/sail artisan key:generate
vendor/bin/sail npm install
vendor/bin/sail npm run build
vendor/bin/sail artisan migrate:fresh --seed
```

Open `http://localhost`. With `APP_ENV=local` the seed adds invented demo data; every demo account (staff and client
portal) uses the password `password` (see `database/seeders/DemoSeeder.php`). Set `ADMIN_NAME`, `ADMIN_USERNAME`, `ADMIN_EMAIL` and
`ADMIN_PASSWORD` in `.env` before seeding to also get your own administrator (asked to change the password at first
sign-in).

Day-to-day:

```bash
vendor/bin/sail up -d          # start
vendor/bin/sail npm run dev    # Vite with hot reload (or npm run build)
vendor/bin/sail artisan …      # any Artisan command
vendor/bin/sail stop           # stop
```

Every command runs inside the container (`vendor/bin/sail …`); nothing is installed on the host.

## Seeders

| Seeder | Runs | Content |
|---|---|---|
| `DatabaseSeeder` | every environment, production included | roles, public holidays, design office catalogues, the initial administrator from the `ADMIN_*` values (only if no administrator exists) |
| `DemoSeeder` | only `local` and `demo` (refuses anything else) | invented users, work sites, audits, equipment, billing… for every module |

Identity and module settings have their defaults in the settings migrations (`database/settings`).
Until the first production release, schema changes edit the original `create_…` migration and the database is rebuilt
with `migrate:fresh --seed`; incremental migrations start after go-live.

## Tests and checks

```bash
vendor/bin/sail artisan test --compact        # PHP feature and unit tests
vendor/bin/sail bin pint                      # code style
vendor/bin/sail bin phpstan analyse --memory-limit=1G   # static analysis (Larastan)
vendor/bin/sail npm run test                  # JavaScript / TypeScript tests (Vitest)
vendor/bin/sail npm run typecheck             # TypeScript
vendor/bin/sail npm run build                 # front-end build
```

Guard tests also fail when a user-facing text is not translated in all five languages, when a route or Livewire action
has no authorisation check, or when a customer name appears in the code.

Browser tests (Playwright: screenshots of every main screen at 1440, 1024 and 390 px, and the recheck acceptance
flows) need a dedicated dataset; see the comment at the top of `playwright.config.ts`. Their output goes to
`test-results/` (ignored by Git, safe to delete). First install the browser once:

```bash
docker compose exec raccord npx playwright install-deps chromium
vendor/bin/sail npx playwright install chromium
vendor/bin/sail npm run test:browser
```

Some OTDR tests compare against real operator files; they are skipped unless `OTDR_SAMPLES_DIR`,
`OTDR_EXAMPLES_DIR` and `OTDR_REFERENCE_DIR` point to those files (never committed).

## Production

One installation per customer on its own server (Ubuntu LTS, nginx, PHP 8.5-FPM, MySQL 8.4).

1. **Environment** — copy `.env.example` to `.env` and set at least: `APP_ENV=production`, `APP_DEBUG=false`,
   `APP_URL`, `APP_KEY` (`php artisan key:generate`), database credentials, `MAIL_*` (sender and SMTP relay),
   `ADMIN_*` (first administrator), `TRUSTED_PROXIES` / `TRUSTED_HOSTS` when behind a proxy, and
   `ANTHROPIC_API_KEY` if the AI features are used. `PLATFORM_ENABLED=false` switches the whole installation off.
2. **Deploy**
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   php artisan migrate --force
   php artisan db:seed --force          # base data only; demo data refuses production
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
3. **Scheduler (required)** — one cron line for the application user. It runs the design office deadlines and
   dossier generation every minute, and the daily retention clean-ups (GPS, departed users, orphan files, old logs…):
   ```
   * * * * * cd /path/to/raccord && php artisan schedule:run >> /dev/null 2>&1
   ```
4. **Queue worker (required)** — thumbnail retries are queued (`QUEUE_CONNECTION=database`); keep a worker running
   (systemd or Supervisor):
   ```
   php artisan queue:work --sleep=3 --tries=3 --max-time=3600
   ```
   Restart it after each deploy with `php artisan queue:restart`.
5. **Web server** — nginx serves `public/` with `public/index.php` as the only PHP entry point, HTTPS only, and an
   upload limit of at least 128 MB (`client_max_body_size`; PHP `upload_max_filesize = 20M`, `post_max_size = 128M`,
   `max_file_uploads = 300`, `memory_limit = 512M`). Long timeouts are only needed on the ZIP and export routes.
6. **Files and backups** — uploaded files live on the private disk `storage/app/private`; back up the database and
   that folder. `storage/` and `bootstrap/cache/` are the only folders the application writes to.
7. **After the first deploy** — sign in with the `ADMIN_*` account, set the company identity and switch on the
   modules on the platform settings page, then create the users.

## Project rules

The coding rules for this project (English code, translations, white label, security, migrations, front-end
organisation) are in `CLAUDE.md`. Local-only material (functional specifications, legacy reference, migration and
deployment kits) lives in `docs/`, which is not versioned.

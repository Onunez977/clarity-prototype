# Clarity concept prototype

This repository is a **sandbox copy** of Insight LPR Clarity (`insight-lpr/platform`, `main`) for visual and UI experiments. It is not production, it is not connected to Insight infrastructure, and it must not be pointed at a real customer database.

Every HTML page shows a fixed badge: **Concept prototype – sample data**.

## Demo login

Open [http://127.0.0.1:8080/app/login](http://127.0.0.1:8080/app/login).

| Field | Value |
| --- | --- |
| Email | `test@tester.com` |
| Action | **Send OTP** |

`APP_ENV=local` skips the one-time password, so that click lands in the sample organization **Test Company** (admin user “Admin”). The account’s stored password is `password`; the login screen does not ask for it.

A second shortcut, only on localhost / `127.0.0.1` / `*.test` while `DEV_LOGIN_ENABLED=true`, is [http://127.0.0.1:8080/__qa/login/1](http://127.0.0.1:8080/__qa/login/1).

After login the nav includes Hits, Scout → Org Map (Bird’s Eye), Company Metrics (the home dashboard), and Account → Users.

## Run it

### On a machine that already has PHP 8.3, Composer, Node 22, Postgres 17 + PostGIS, and Redis

```bash
make prototype
```

That is `./scripts/prototype-up.sh`. It installs PHP and JS dependencies, builds the Vite assets, seeds the database the first time, and serves [http://127.0.0.1:8080](http://127.0.0.1:8080).

Create the database once if it does not exist (Postgres **17**, because the schema dump uses Postgres 17 syntax):

```bash
sudo -u postgres psql -c "CREATE ROLE platform LOGIN SUPERUSER PASSWORD 'platform';"
sudo -u postgres psql -c "CREATE DATABASE zod OWNER platform;"
```

The app user is a superuser so `dev:migrate` can create PostGIS extensions. That is acceptable only on this local sandbox.

Rebuild the sample database from scratch:

```bash
PROTOTYPE_RESEED=1 make prototype
```

`php artisan dev:migrate --seed` drops and recreates the local `zod` database. Do not point `DB_HOST` at anything but localhost.

### Docker

```bash
cp -n .env.example .env   # optional; the container writes its own key
docker compose up --build
```

`docker-compose.yml` starts PostGIS 17, Redis, and the app on port 8080. The first boot runs `php artisan dev:migrate --seed`. This path was not executed in the environment that assembled the prototype (Docker was not installed there). `make prototype` on the host is the path that was booted and screenshotted.

## What was copied

Plain files from `insight-lpr/platform` `main`. No git history was imported, and nothing was pushed, branched, or opened against that repository.

Private Composer packages are vendored under `packages/` so `composer install` does not need a GitHub token:

- `packages/black-adam` (`insight-lpr/black-adam` 3.4.15)
- `packages/laravel-clickhouse-eloquent`
- `packages/laravel-zod-migrations`

`packages/laravel-zod-migrations/database/schema/*.sql` has the RDS-only extensions commented out (`aws_commons`, `aws_s3`, `pg_repack`, `pg_hint_plan`, `pg_partman`). The rest of the production schema dump is loaded as-is and contains no customer rows.

## Sample data

`php artisan dev:migrate --seed` runs the app’s own local seeders:

- `InsightOrgSeeder` — internal org named InsightLPR (the product’s own system org, not a customer)
- `LocalDataSeeder` — **Test Company**, admin `test@tester.com`, kits, scans, hits, hotlists
- `ClarityScoutClickhouseSeeder` and `OttoSeeder` — Scout paths, Dallas zones (Downtown Dallas, DFW Airport, North loop), Otto threads, extra faker orgs such as “Otto QA Client”

Plates and org names come from the existing factories (faker), not from production.

## What was stubbed, and where

| Integration | Prototype behavior | Where |
| --- | --- | --- |
| Plate / vehicle images | Local SVG `/prototype/sample-image.svg` instead of `images.insightlpr.com` / `.io` | `app/helpers.php` `scan_img()` when `APP_ENV=local` |
| ClickHouse | `CLICKHOUSE_DRIVER=pgsql` against the same Postgres database. Date helpers and a few aggregates are rewritten so Company Metrics and Scout queries run. | `.env.example`, `database/schema/local-clickhouse-schema.sql`, `app/Support/Prototype/ClickhouseSqlCompat.php`, `AppServiceProvider` |
| ClickHouse `LIMIT BY` | No-op on Postgres (a plate may appear more than once) | `AppServiceProvider::rewriteClickhouseSqlForPostgres()` |
| `clarity.hotlist` | View over the seeded Postgres hotlists | `database/schema/local-clickhouse-schema.sql` |
| `tiger.state.simplified_geom` | Columns added after the schema load so scan inserts succeed. State polygons are **not** downloaded from Insight S3. | `config/zod-migrations.php` |
| S3, SQS, RDN, Datadog, Nightwatch, Twilio, ConvertAPI, Geocodio, Matcher, Bedrock / Otto live model | Empty credentials, mailer `log`, queue `sync`, filesystem `local`, `RDN_CASE_INFO_ENABLED=false`, `AWS_BEDROCK_USE_DEFAULT_CREDENTIALS=false` | `.env.example` |
| Billing portal | `/billing` renders a local stub instead of Biller Genie | `routes/web.php`, `resources/views/prototype/billing-stub.blade.php` |
| Realtime (Soketi / Pusher) | `BROADCAST_CONNECTION=log`. Scout live GPS and Otto streaming do not push. | `.env.example` |
| Google Maps | Key left blank. Scout still loads drivers, stats, and the live-hit list; the map pane has no tiles until `GOOGLE_MAPS_API_KEY` is set in `.env`. | `.env.example` |
| Prototype badge | Injected before `</body>` on full HTML documents | `app/Http/Middleware/PrototypeBadge.php` |
| Demo login hint | Shown on the Filament login form | `resources/views/prototype/demo-login.blade.php` |
| Deploy workflows | Push/PR/schedule triggers removed so this repo cannot auto-deploy. A Google Maps key that was hardcoded in the upstream workflow was deleted. | `.github/workflows/` |

Only `.env.example` is committed. `.env` (with the generated `APP_KEY`) stays local.

## What could not be made to work

- **Map tiles and live GPS.** Bird’s Eye shows the driver roster, scan/hit stats, and the “Live hits pending repossession” list (sample plates around Indianapolis). No kit reports a GPS fix, and Google Maps has no API key, so the map canvas is empty. Set `GOOGLE_MAPS_API_KEY` locally if you need tiles. Do not commit the key.
- **Otto chat.** Seeded threads are in the database, but sending a new message calls Bedrock, which has no credentials on purpose. The assistant UI will not complete a new turn.
- **Some Company Metrics cards** still depend on ClickHouse tables that are not created locally (`clarity.recovery_scans`, `clarity.recoveries`, `clarity.kits`). Those cards fail soft and show an empty value. The open-assignment totals on the home dashboard are populated (about 105 open assignments for Test Company).
- **Scout Users** (`/app/scout-users`) can render with an empty table even though Org Map lists the seeded kits. The driver roster on Org Map is the populated Scout surface.
- **State / illegal-scan polygons** are not loaded. `StateSeeder` downloads `state.csv` from Insight S3 and is not part of the default seed.
- **Docker Compose** is included and uses the same seed command, but it was not booted in the environment that produced the screenshots.
- **Upstream Pint/PHPUnit CI** is manual-only. It expects the Insight CI database.

## Screens that were checked

Logged in as `test@tester.com` on `http://127.0.0.1:8080`:

- `/app` — Company Metrics home, with open-assignment counts and the full nav
- `/app/hits` — live hits and a plate list
- `/scout/org-map` — Bird’s Eye, stats, and the live-hit list
- `/app/users` — admin users

## Keeping visual edits easy to port

Application structure, Filament resources, Vue Scout app, and CSS are the upstream tree plus the seams listed above. Prefer UI edits in `resources/` and `app/Filament/`. Do not copy `packages/` changes or `ClickhouseSqlCompat` back to `insight-lpr/platform` unless you mean to.

Account → Configuration (`/app/configuration`, `App\Filament\Pages\Configuration`) is a prototype-only admin page. Toggles are stored on `org_meta.properties` and do not call Clear Plan, RDN, or a lender. RDN Statistics Push uses the existing key `platform_repo_pro_rdn_metrics_push`, shared with Manage Subscription. Add another toggle by appending one option to `Configuration::sections()`.

Locations & Coverage on that page stores company locations and a coverage zone in `org_locations` and `org_coverage_zones` (platform schema). Test Company is seeded with a main office, two storage facilities, a satellite office, and a 25-mile company-wide radius. Add a location type by adding a case to `App\Enums\OrgLocationType`. The map uses the same Google Maps and drawing libraries as zones; without `GOOGLE_MAPS_API_KEY` the page shows a coordinate plot and the list instead of tiles.

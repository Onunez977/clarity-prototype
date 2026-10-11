# Release readiness scan

How release-prep Step 5b turns the `$TAG..HEAD` diff into `.context/release-readiness.md` — a
deploy-time checklist of the operational work the release implies, much of which is **not automated**
in this repo. Read-only static analysis (every file list piped through `rel_filter`, §1.5 of
[`release-inventory.md`](release-inventory.md)) plus cheap cross-checks against the provisioned env.

**Markers.** Tag each item:
- 🚨 **GATE** — must happen before/with the deploy or the release breaks.
- ⚠️ **decision** — needs a human call (rollout, run-or-not).
- ℹ️ **note** — informational; no action required to ship.

Advisory only — release-prep never runs migrations, applies schema, sets env, or rolls out flags.
The 🚨 items are echoed at the top of the Step 11 summary so they can't be missed.

```bash
RANGE="$TAG..HEAD"
changed() { git -C "$TARGET" diff --name-only "$RANGE" -- "$@" | rel_filter; }
```

## Detections

### 0. Release candidate 🚨
The Step 1a check, surfaced here: `HEAD == origin/main` (after `git fetch origin main`). `behind>0`
→ 🚨 candidate is stale (main moved past you). `ahead>0` → 🚨 local commits aren't on origin/main yet
(the release won't contain them). Clean → ℹ️ "HEAD is origin/main (`<short-sha>`)". The tag step
(10c) refuses unless this is clean.

### 1. Migrations 🚨
```bash
changed database/migrations
```
List every pending migration in the range (filenames sort by timestamp; includes the `zod/`
subdir). For each, grep the file for destructive/heavy ops and flag them:
`grep -nE 'dropColumn|dropTable|->drop\(|dropIfExists|dropForeign' <file>` (destructive),
`grep -nE 'create.*index|->index\(|->unique\(|CREATE (UNIQUE )?INDEX|FUNCTION' <file>` (heavy / locking).
- 🚨 **kamal-deploy does NOT auto-run migrations** (the step is commented out in
  `.github/workflows/kamal-deploy.yml`) — they are a deliberate manual step: `php artisan migrate
  --force` (prod). Note the custom `platform`/`zod` dual-schema; `php artisan dev:migrate` is
  dev/CI-only (it does `migrate:fresh`). The report MUST list "run these N migrations on deploy".
- ℹ️ Verification: bootstrap already ran them clean in the QA env (Step 4) — say so.

### 2. ClickHouse 🚨
```bash
changed app/Clickhouse app/Queries/Client
```
For each changed query class, extract referenced tables:
`grep -oiE '(FROM|JOIN)[[:space:]]+(clarity|zod)\.[a-z_]+' <file> | sort -u`, plus `DB::clickhouse('<name>')`
calls. Cross-check each table against the local schema:
`grep -i 'CREATE .*<table>' database/schema/clickhouse-schema.sql`.
- 🚨 Any referenced table/column **absent from the schema** is a cross-repo gate: the ClickHouse
  Cloud schema is owned by the separate **chlocal** repo (Debezium CDC + tiered materialized views)
  and must be applied to CH Cloud, in dependency order (source table → MV), **before** the platform
  release deploys, or those queries 500. If `~/Herd/chlocal` exists, cross-check
  `chlocal/migrations/cloud_export/cloud_clarity_schema.sql` / `cloud_zod_schema.sql`; else flag
  "verify the table exists in ClickHouse Cloud (chlocal)".
- 🚨 Flag any change to `database/schema/clickhouse-schema.sql` itself (a schema sync landed).
- 🚨 **CH provider:** `CH_STRATEGY` must be `docker` (real local ClickHouse) for a release — the
  regression battery exercises CH-heavy surfaces. If it fell back to `pgsql-bridge` (Docker
  unavailable), flag "ClickHouse on the pgsql bridge — CH-backed regression NOT exercised; start
  Docker and re-run." `none` → CH untestable.
- ℹ️ PeerDB CDC: new `zod.*`-fed tables carry ~seconds of replication lag — verify against live data.

### 3. Env / config 🚨
```bash
changed config
```
In the changed `config/**` files, find env reads **without a default** (single-arg form):
`grep -noE "env\('[A-Z0-9_]+'\)" <file>` (a second arg = has a fallback, lower risk). Cross-check
each key against `.env.example`.
- 🚨 New required keys must be present in the production `.env` **before** `config:cache` runs at
  deploy (kamal-deploy caches config) — otherwise they bake as null. List them for ops.

### 4. Feature flags (Pennant) ⚠️
```bash
git -C "$TARGET" diff "$RANGE" -- app/Enums/FeatureEnum.php app/Enums/ProTierEnum.php app/Providers/PennantServiceProvider.php
```
- New `case … = 'platform_repo_pro_*'` in `FeatureEnum.php` → a new flag.
- Tier-membership changes in `ProTierEnum.php` `features()` → which tier ships it (live on deploy for
  orgs on that tier; pro gating reads shared `zod public.org_meta` `platform_repo_pro*`).
- Experimental config toggles (`config('app.experimental.*')`, env `*_ENABLED` default `false`).
- ⚠️ Emit a rollout checklist: which flags are off-by-default (need an explicit env / Kamal-secret
  flip to go live) vs. which ship live via tier. This is the same gating that keeps hidden features
  out of the changelog (e.g. Clarity Scout) — surface the rollout decision here.

### 5. Permissions / Filament resources ⚠️
```bash
git -C "$TARGET" diff --name-only "$RANGE" -- 'app/Filament/**/*Resource.php' app/Console/Commands/SeedRolesAndPermissions.php
```
- New Filament resources or new permissions → ⚠️ `php artisan app:seed-permissions` is **not** in the
  deploy workflow; flag that it must be run on deploy when permissions changed.

### 6. Queues / scheduled tasks ℹ️
```bash
git -C "$TARGET" diff "$RANGE" -- routes/console.php config/horizon.php config/queue.php
```
New `Schedule::command`/`Schedule::job` will start running on deploy; queue/Horizon config changes.
Redis-cluster init is already handled by kamal-deploy. ℹ️ Note QA ran with `QUEUE_CONNECTION=sync`
(jobs inline) — async-specific behavior (batching, delays, retries, Horizon throughput) was **not**
exercised; if the release changed queued-job mechanics, verify on the real redis path post-deploy.

### 7. Dependencies / assets ℹ️
```bash
git -C "$TARGET" diff "$RANGE" -- composer.json package.json
```
The Kamal image bakes assets + runs composer install; note any new PHP extension or service implied.

### 8. Post-deploy commands / backfills ⚠️
```bash
changed app/Console/Commands
```
New one-off artisan commands that read like data backfills/migrations → ⚠️ likely need a manual run
after deploy. List them with their signatures.

### 9. CI gate 🚨
Confirm HEAD is green — the same gate `lint_check_and_test.yml` / `deploy.yml` enforce (Pint +
`php artisan test`). If a CI status is reachable (`gh run list` for the branch/sha), report it;
otherwise note "verify CI green before tagging." Don't cut a release on red.

## Report template (`.context/release-readiness.md`)

```markdown
# Release readiness — <REL_VERSION> (<TAG>..HEAD, <today>)

## 🚨 Gates (do before / with deploy)
- [ ] Release candidate: HEAD == origin/main (`<short-sha>`) | ⚠️ ahead/behind: <detail>
- [ ] Migrations (N): run `php artisan migrate --force` — <list>; destructive: <…/none>
- [ ] ClickHouse schema: <tables needing a chlocal/CH-Cloud apply, in order> | none
- [ ] ClickHouse provider: docker (real CH) ✓ | ⚠️ pgsql-bridge — CH regression NOT exercised, start Docker + re-run
- [ ] Env: set <KEYS> in production .env before config:cache | none
- [ ] CI green on <sha> (<status>)

## ⚠️ Decisions
- [ ] Flag rollout: <flag — off-by-default, flip X to go live | ships via <tier>>
- [ ] Permissions: run `app:seed-permissions` (new resource <X>) | n/a
- [ ] Post-deploy backfill: <command> | none

## ℹ️ Notes
- Scheduled/queue: <new jobs that start on deploy> | none
- Dependencies/assets: <changes> | none
- ClickHouse replication lag on new CDC-fed tables: <tables> | n/a

## Post-deploy verification
- [ ] Migrations applied; `php artisan migrate:status` clean
- [ ] New CH tables resolve (read-only query returns rows)
- [ ] Key touched routes load without 5xx
- [ ] Read-only CH verification queries for headline features: <queries>
```

Populate every line from the detections above; collapse a category to "none"/"n/a" when the range
doesn't touch it rather than dropping the line, so the report is a complete checklist each release.

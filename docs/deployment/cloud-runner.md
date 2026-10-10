# Cloud runner (claude.ai/code cloud sessions)

A claude.ai/code cloud session on this repo boots a working Clarity by itself: Postgres,
Redis and a **local** ClickHouse, migrated and seeded, served at
`http://clarity-cloud.test:8000`. No Herd, VPN or production credentials. Modeled on
Guardian's setup (insight-lpr/overwatch#1060).

| Piece | What it does |
|---|---|
| [`scripts/cloud/setup.sh`](../../scripts/cloud/setup.sh) | Environment **setup script**: installs PHP 8.3, PostgreSQL 17 + PostGIS, ClickHouse 26.2, Redis, Composer; creates `zod` / `zod_testing`. Runs once, then Anthropic caches the filesystem snapshot. |
| [`.claude/hooks/cloud-session-start.sh`](../../.claude/hooks/cloud-session-start.sh) | `SessionStart` hook ([`.claude/settings.json`](../../.claude/settings.json)). Exits immediately unless `CLAUDE_CODE_REMOTE=true`, so local sessions are untouched. Starts services, writes `.env` / `.env.testing`, runs `app:preview-bootstrap` once per VM, serves the app, and prints an ok/FAIL summary. Full log: `storage/logs/cloud-session-start.log`. |
| `php artisan app:preview-bootstrap` | Schema, tiger.state patch, seed personas, and a ClickHouse copy of the seed. Refuses any non-local DB/ClickHouse host. |

## Deploying it to Claude (one-time)

### 1. Prerequisites

- **GitHub access to this repo.** The Claude GitHub App must be installed on the `insight-lpr` org with access to `insight-lpr/platform` (claude.ai/code prompts for this on first use), or connect via `/web-setup` from a CLI whose `gh` token can read the repo.
- **A read-only token for the private Composer packages.** Create a GitHub **fine-grained personal access token**:
  - Resource owner: `insight-lpr`
  - Repository access: only `laravel-zod-migrations`, `black-adam`, `laravel-clickhouse`
  - Permissions: **Contents: Read-only** (nothing else)
  - Short expiry; note it on the calendar.

  > Environment variables in claude.ai are **not secret** — anyone who can use the environment can read them. Keep this token scoped exactly as above and never put production credentials in the environment.

### 2. Create the environment

1. Open [claude.ai/code](https://claude.ai/code). Click the **cloud icon showing the current environment's name** (the row above the message box) → **Add cloud environment**.
2. **Name:** `Clarity`
3. **Network access:** **Custom**, keep the default (Trusted) domains included, and add these under **Allowed domains**, one per line:

   ```
   apt.postgresql.org
   www.postgresql.org
   packages.clickhouse.com
   ppa.launchpadcontent.net
   api.launchpad.net
   getcomposer.org
   ```

   (GitHub, Packagist, npm and the Ubuntu mirrors are already in the default list.)
4. **Environment variables:** exactly one line, with the token from step 1:

   ```
   COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}'
   ```

   **Do not** add `DB_*`, `CLICKHOUSE_*` or `APP_*` here. Environment variables become real process env vars, which override `.env.testing` and would point the test suite at the dev database. The hook writes those values into the env files instead.
5. **Setup script:** paste the full contents of [`scripts/cloud/setup.sh`](../../scripts/cloud/setup.sh). The committed file is the source of truth.
6. Save.

### 3. First session (verification)

1. Start a session with **only** `insight-lpr/platform` selected (a session with several repositories does not load the repo's hooks), on a branch that contains this setup, using the `Clarity` environment.
2. The first start runs the setup script (~1 min) and then the hook (~70 s). The hook's summary should read all `ok`:

   ```
   Clarity cloud session setup:
     ok    Postgres
     ok    Redis
     ok    ClickHouse (refreshable views stopped)
     ok    /etc/hosts clarity-cloud.test
     ok    composer install
     ok    .env + .env.testing + APP_KEY
     ok    databases (app:preview-bootstrap)
     ok    app on http://clarity-cloud.test:8000
   ```
3. Ask Claude to confirm the app: `curl -s -o /dev/null -w '%{http_code} %{redirect_url}' http://clarity-cloud.test:8000/__qa/login/1` → `302 http://clarity-cloud.test:8000/app`.
4. Resume or start a second session: the hook should finish in about a second.

### 4. Keeping it current

- **Changing `scripts/cloud/setup.sh`:** merge the change, then re-paste it into the environment. Changing the setup script or the allowed domains rebuilds the cached snapshot on the next session; the cache also expires on its own after roughly seven days.
- Keep the setup script under ~5 minutes, or the environment isn't cached and every session pays for it (it currently takes ~1 minute).
- **Rotating the token:** create a new one with the same scope and replace the `COMPOSER_AUTH` value. Only a VM without `vendor/` uses it.

## Using a session

- Log in: `http://clarity-cloud.test:8000/__qa/login/1` (`test@tester.com`, admin). Other personas: `peer-admin@cloud-smoke.test` (a second org) and `client@cloud-smoke.test` (client portal) — find their ids with tinker.
- Tests: `php artisan test --compact <file>` uses `zod_testing` and the same ClickHouse server, so `RequiresClickhouse` tests **run** (as in CI) — and they truncate the seeded `clarity.*` / `zod.*` tables. Afterwards, restore the seed with `php artisan app:preview-bootstrap` (seconds; it re-syncs ClickHouse from Postgres).
- Rebuild from scratch: `php artisan app:preview-bootstrap --fresh`.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| No `Clarity cloud session setup:` summary at all | Hook didn't run: the session has more than one repository, or the branch predates `.claude/hooks/cloud-session-start.sh`. |
| `FAIL composer install` | `COMPOSER_AUTH` missing, malformed JSON, expired, or the token can't read one of the three package repos. |
| Setup script fails on `apt-get` / `curl` | A host is missing from **Allowed domains** (step 2.3). |
| `FAIL databases (app:preview-bootstrap)` | Read `storage/logs/cloud-session-start.log`. A `Refusing to bootstrap` line means a `DB_*`/`CLICKHOUSE_*` env var was set in the environment — remove it. |
| Redirects go to `https://…:8000` | `FORCE_HTTPS` isn't `false` in `.env` — the hook sets it; check nothing overrides it. |
| ClickHouse-backed pages empty after running tests | `RequiresClickhouse` tests truncated the seed — run `php artisan app:preview-bootstrap`. |

## Gotchas

- ClickHouse database names must be the literal `clarity` / `zod`: several query classes hardcode them.
- Refreshable materialized views are stopped on every ClickHouse start (they would append into the seeded tables).
- The cloud VM is x86_64; the setup script was verified on arm64 (same packages, multi-arch).

## Verified

In an `ubuntu:24.04` container standing in for the cloud image: setup script ~55s; first hook run 66s, all steps ok; resume 0.15s; `/__qa/login/1` → `/app` 200; a plain and a `RequiresClickhouse` test file pass; `app:preview-bootstrap` restores the seed after the ClickHouse test. **Not yet run in a real claude.ai cloud session.**

# Agent QA — portable build-spec (Claude Code + Codex worktrees)

> **What this document is.** A self-contained build spec. Paste it into a fresh
> Claude Code **or** Codex session at the root of a target Laravel project, and the
> agent reading it will build an `agent-qa` skill there — the same skill described
> below, adapted to *that* project. It is written **to the agent doing the build**,
> not to a human. If you're the agent: follow it top to bottom. Do **§2 (discovery)
> first**; everything after fills in from what you learn there.
>
> **What it is NOT.** It is not a drop-in copy of any one project's skill. The
> project it was distilled from ("the origin project") used Postgres + a separate
> ClickHouse analytics DB, a `zod`-schema database, a PostGIS `tiger` schema, and a
> `/__qa/login` route redirecting to a Filament `/admin` panel. **None of that is
> assumed about your target.** Wherever an origin-project value appears below, it is
> fenced in an **`EXAMPLE`** block and labelled — it shows you the *shape* of an
> answer, never a value to paste. Your target has its own database, its own login,
> its own seeders. Discover them.

---

## 0. The goal, in one paragraph

`agent-qa` is **pre-QA for the current branch**: one trigger reads the local app URL,
makes sure the branch has a working seeded database and a local login, diffs the branch
against its base to build a focused checklist, drives a real browser through that
checklist (logging in through a local dev-login route), scans the app log for new
errors, and writes a report — so a human starts manual QA from a known-working baseline
instead of from "does it even boot?". It catches trivial breakage (broken login,
missing seeder, 500 on a touched route, JS console errors). It is **not** a substitute
for human QA and it does **not** run the test suite.

It must work from **three** launch contexts, detected by the current path:

| Mode | Launched from | Provisioning |
|---|---|---|
| `claude-worktree` | `…/.claude/worktrees/<name>` (a git worktree) | auto-provisioned by a `SessionStart` hook before the skill runs |
| `codex-clone` | `…/.polyscope/clones/<hash>/<name>` (a full clone) | **nothing auto-provisions it** — the skill must invoke the bootstrap script itself |
| `checkout` | anything else (the developer's main checkout) | **read-only** — never mutate the developer's env or DB |

The asymmetry between the first two is the whole reason this is a port and not a copy:
a Claude worktree is provisioned for you; a Codex/Polyscope clone is not. The fix is
**one shared bootstrap script** that both modes use — Claude's hook calls it on session
start, and in Codex the skill calls the very same script explicitly.

---

## 1. How to use this document (agent: read once, then start)

1. You are at the **main checkout** of the target Laravel project. You will *write
   files* (a skill, a hook, maybe a route) — you do not need to be in a worktree to
   build them.
2. **Assumption — the only one:** [Laravel Herd](https://herd.laravel.com) is installed
   and the project already serves locally over an `https://<something>.test` host.
   Everything else (database, login, seeding) you will **discover**, not assume.
3. Do the work in this order:
   - **§2 Discovery** — interview the repo, write a "Project profile" you keep in front
     of you. *Do not skip or guess.*
   - **§3** — write `.claude/skills/agent-qa/SKILL.md`, filling every `{{PLACEHOLDER}}`
     from the profile.
   - **§4** — write `.claude/hooks/bootstrap.sh` (the shared provisioning script) and
     register the Claude `SessionStart` hook.
   - **§5** — write `.claude/skills/agent-qa/agent-browser-reference.md` (near-verbatim).
   - **§6** — run the adaptation checklist to confirm nothing was left as a placeholder.
4. **Install check (one-time, tell the human if it fails):** the browser pass needs
   Vercel's `agent-browser` CLI.
   ```bash
   command -v agent-browser >/dev/null && agent-browser --version \
     || echo "MISSING: install with 'brew install agent-browser && agent-browser install'"
   ```
   The skill itself also gates on this at run time, so a missing CLI degrades to
   "manual QA" rather than failing the build.

---

## 2. Project discovery interview — RUN THIS FIRST

Inspect the target repo and answer each question **about this project**. Record answers
in a profile block you keep in working memory and use to fill `{{PLACEHOLDERS}}` later.
Each question tells you where to look. The `EXAMPLE` blocks are the origin project's
answers — they show the *shape*, never a value to copy.

### Q1 — Local dev URL + per-branch site naming
- `grep -E '^APP_URL=' .env` for the current host.
- Decide the naming for a **per-branch isolated Herd site** (slug from the branch).
```text
EXAMPLE (origin project): APP_URL=https://clarity.test
                          per-branch: https://clarity-<safe_branch>.test
```
→ fills `{{SITE_BASENAME}}` (e.g. the part before `-<branch>.test`).

### Q2 — Database stack
- `config/database.php` `'default'`, plus `.env` / `.env.testing`: driver, host, port,
  the **dev** DB name and the **testing** DB name.
- Note whether there is **more than one** datastore (a second analytics DB, a cache
  store with schema, etc.). Most projects have one.
```text
EXAMPLE (origin project): pgsql @ 127.0.0.1:5432, user root, dev DB "zod",
                          testing DB "zod_testing", PLUS a separate ClickHouse
                          analytics DB. Your target most likely has neither of those —
                          find what it actually has.
```
→ fills `{{DB_DRIVER}}`, `{{DB_HOST}}`, `{{DB_PORT}}`, `{{DB_USER}}`, `{{DEV_DB_BASE}}`,
`{{TEST_DB_BASE}}`, and `{{EXTRA_DATASTORES}}` (usually "none").

### Q3 — Per-branch DB naming + creation
- How is an **isolated branch database** named and created for *this* engine?
  - Postgres → `createdb -h <host> -U <user> <name>`; identifiers truncate at 63 chars.
  - MySQL/MariaDB → `mysql -e 'CREATE DATABASE …'`; 64-char limit.
  - SQLite → a per-branch file under `database/`.
- Apply a length-safe slug: lowercase, non-alphanumerics → `_`; if too long, cap and
  append a short hash of the full branch (so two long branches can't collide).
```text
EXAMPLE (origin project): DEV_DB = zod_<wt_id>, TEST_DB = zod_testing_<wt_id>,
                          wt_id = safe_branch, or (if >40 chars) first 33 chars + "_" + 6-char sha.
```
→ fills `{{DB_CREATE_CMD}}`, `{{DB_EXISTS_CHECK}}`, and the slug rule.

### Q4 — Migrate + seed commands
- What does this project run to migrate and seed? Check `composer.json` scripts and
  `php artisan list` for custom commands; inspect `database/seeders/`.
- Capture the **migrate** command, the **seed** command, and any **extra** seeders
  (permissions, roles) the project runs separately.
```text
EXAMPLE (origin project): migrate = "php artisan dev:migrate", seed = "php artisan db:seed --force",
                          plus "php artisan app:seed-permissions".
```
→ fills `{{MIGRATE_CMD}}`, `{{SEED_CMD}}`, `{{EXTRA_SEED_CMDS}}`, and `{{MIGRATE_STATUS_CMD}}`
(usually `php artisan migrate:status`).

### Q5 — Dev-login mechanism
- Does a local force-login route already exist?
  `grep -rni 'loginUsingId\|dev.login\|__qa\|force.login' routes/ app/`.
- **If yes:** the skill reuses it — record its path and where it lands.
- **If no:** you will build one following the **four-guard pattern** (see the reference
  implementation in §3-A). It must redirect to *this project's* post-login landing —
  **discover the landing route**, do not assume `/admin`. (Filament panel path? A
  dashboard route? The home route after auth?)
```text
EXAMPLE (origin project): route GET /__qa/login/{id?} → Auth::loginUsingId(id) → redirect('/admin')
```
→ fills `{{QA_LOGIN_PATH}}` (e.g. `/__qa/login`) and `{{POST_LOGIN_PATH}}` (the landing).

### Q6 — Auth model for the login
- `config/auth.php`: which guard / provider / user model does web auth use?
- Which **seeded user** should dev-login authenticate as (id, and how it's seeded —
  factory? a known `test@…` account)?
- Is there any **post-login session priming** (impersonation, tenant/org selection,
  team context) the app needs before authenticated pages work? Replicate **only** what
  this project needs.
```text
EXAMPLE (origin project): logs in user id=1 (seeded test@tester.com), then primes a
                          session "admin_impersonator" key to satisfy downstream gates.
                          Your project may need nothing, or something entirely different.
```
→ fills `{{LOGIN_USER_ID}}`, `{{LOGIN_USER_NOTE}}`, `{{SESSION_PRIMING}}` (or "none").

### Q7 — Project-specific QA-correctness fixes
- Is there a **local-only gotcha** that blocks a clean seeded run? Look for: a datastore
  the seeders need that isn't running locally; a DB extension/trigger that needs
  patching; an env toggle that silently no-ops a seeder.
- Add a targeted fix **only if the project actually has one.** Default answer: **none.**
- Do **not** carry over the origin project's fixes.
```text
EXAMPLE (origin project, DO NOT COPY): swapped a ClickHouse driver to a local pgsql
                          bridge so analytics seeders wouldn't no-op; patched PostGIS
                          columns on a `tiger.state` table so a trigger wouldn't fail.
                          These are origin-specific. Your target is its own thing.
```
→ fills `{{CORRECTNESS_FIXES}}` (a list of {detect, fix} steps, or "none").

### Q8 — Base-branch candidates
- `git branch -a` and the repo's conventions: what long-lived branches do feature
  branches fork from?
```text
EXAMPLE (origin project): main scanner-app staging develop
```
→ fills `{{BASE_CANDIDATES}}` (space-separated list, most-likely first).

### Q9 — Log location
- Confirm where the app writes logs for the post-run error scan. Laravel default is
  `storage/logs/laravel.log` (or dated `laravel-YYYY-MM-DD.log`).
→ fills `{{LOG_GLOB}}`.

> **Profile block** — keep something like this in front of you and reference it as you
> write §3–§5:
> ```
> SITE_BASENAME, DB_DRIVER/HOST/PORT/USER, DEV_DB_BASE, TEST_DB_BASE, EXTRA_DATASTORES,
> DB_CREATE_CMD, DB_EXISTS_CHECK, MIGRATE_CMD, SEED_CMD, EXTRA_SEED_CMDS,
> QA_LOGIN_PATH, POST_LOGIN_PATH, LOGIN_USER_ID, SESSION_PRIMING,
> CORRECTNESS_FIXES, BASE_CANDIDATES, LOG_GLOB
> ```

---

## 3. Write `.claude/skills/agent-qa/SKILL.md`

Create the skill below, substituting every `{{PLACEHOLDER}}` from your profile. Keep the
structure; the prose is tuned for an LLM running the skill.

### 3-A. Reference implementation: the four-guard dev-login route

Build this **only if Q5 found no existing login.** Adapt the redirect, user id, and any
session priming to your profile (Q6). It is safe to commit because **four independent
guards** each shut the door outside local:

```php
// routes/web.php  — origin-project shape; adapt redirect + user + priming to YOUR project
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

if (app()->environment('local') && config('app.dev_login_enabled') === true) {
    Route::get('{{QA_LOGIN_PATH}}/{id?}', function (Request $request, int $id = {{LOGIN_USER_ID}}) {
        abort_unless(app()->environment('local'), 404);
        abort_unless(config('app.dev_login_enabled') === true, 404);
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 404);
        abort_unless(str_ends_with($request->getHost(), '.test'), 404);

        Auth::loginUsingId($id);
        // {{SESSION_PRIMING}}  — only if YOUR project needs it; otherwise delete this line
        return redirect('{{POST_LOGIN_PATH}}');
    })->middleware('throttle:10,1')->where('id', '[0-9]+');
}
```

Plus `config/app.php`:
```php
'dev_login_enabled' => (bool) env('DEV_LOGIN_ENABLED', false),
```
And `.env` (local only): `DEV_LOGIN_ENABLED=true` and `APP_ENV=local`.

### 3-B. The skill file

````markdown
---
name: agent-qa
description: Autonomously QA's the current branch — works in a Claude Code worktree (auto-provisioned by the SessionStart bootstrap hook), a Codex/Polyscope clone (the skill invokes the same bootstrap script itself, since no hook fires), or the main checkout (read-only against whatever's in .env). On trigger it reads APP_URL from .env, ensures the branch has a seeded DB and a working dev login (mutating only in worktree/clone mode — the main checkout is inspected, never changed), diffs the branch against its base to build a focused diff-anchored QA plan, then fans the browser pass out across parallel subagents that drive the plan via Vercel's agent-browser CLI (logging in through the project's dev-login route) while the orchestrator builds the plan and synthesizes findings, scans the app log for new errors, and writes a pre-QA report. In worktree/clone mode it leaves the DB and env in place so you can interrogate what changed; in checkout mode nothing is mutated. Optionally accepts a Jira ticket key appended to the trigger (e.g. "agent QA this DEV-1915"), folding that ticket's acceptance criteria into the brief. ONLY trigger on the exact phrases (case-insensitive, minor punctuation OK): "agent QA this" / "have the agent QA this" / "agent QA". Do NOT trigger on "QA this", "test this", "click around", "smoke test" — handle those conversationally. The explicit phrases are required because this skill drives a real browser and can mutate .env and the database in worktree/clone mode. If the user expresses agent-QA intent without an exact phrase, ask them to confirm with one.
---

# Agent QA

Self-contained pre-QA for the current branch. One trigger does everything: read
`APP_URL` from `.env`, ensure a seeded DB + working dev login (in worktree/clone mode),
diff the branch to build a checklist, drive a browser through it (or hand off for manual
QA if no browser is available), and report.

The goal is **pre-QA**, not a substitute for human QA: catch trivial breakage and — in
worktree/clone mode — apply local-only correctness so manual QA starts from a
known-working baseline. The DB and env are left **as-is** afterward so you can ask "what
did you change, what's good, what's broken" against the same state the agent saw. In
checkout mode nothing is mutated; missing prerequisites surface as caveats.

## Mental model

Three modes, decided by where you launched from:

- **`claude-worktree`** — running from `…/.claude/worktrees/<name>`. Every worktree is
  auto-provisioned on session start by `.claude/hooks/bootstrap.sh`: it creates a
  branch-derived isolated database, writes branch overrides (`DB_DATABASE`, `APP_URL`,
  …) into the worktree's `.env`, installs deps, migrates, seeds, and `herd link`s. The
  site and DB already exist — no setup, no teardown. This skill layers QA-correctness on
  top, ensures seeded, runs the browser pass, reports.

- **`codex-clone`** — running from `…/.polyscope/clones/<hash>/<name>`. This is a full
  clone, and **no SessionStart hook fires**, so nothing has provisioned it. This skill
  invokes the **same** `.claude/hooks/bootstrap.sh` explicitly to provision, then
  proceeds exactly like worktree mode. (A fresh clone may not even have a `.env` yet —
  the bootstrap script seeds one.)

- **`checkout`** — running from the main checkout (anything not under `.claude/worktrees`
  or `.polyscope/clones`). No provisioning, no mutations. Trust the developer's `.env`
  and DB as-is, read `APP_URL`, inspect prerequisites read-only, run the browser pass.
  Anything that would have been mutated surfaces as a caveat.

Orthogonal to mode is **who does what**. The orchestrating (main-loop) model does all
the reasoning — provisioning, diff analysis, the briefing, partitioning the checklist,
synthesizing the report. The browser pass is fanned out to **up to 3 parallel
subagents**, one per route/feature lane. The token-heavy snapshot/console/network volume
stays in the subagents; the orchestrator only sees compact structured findings. (How
those subagents are spawned depends on the harness — see "Browser pass" below.)

> Overrides live in `.env`, not `.env.local`: vanilla Laravel does not overlay
> `.env.local`. `.env` is gitignored and per-instance, so edits stay scoped to this
> worktree/clone. In checkout mode the skill never touches `.env`.

## Trigger

Triggered by "agent QA this", "have the agent QA this", or "agent QA". If the user
expresses agent-QA intent some other way, do not proceed — ask them to confirm with an
exact phrase.

### Optional Jira ticket key

The user may append a key — "agent QA this DEV-1915". Capture it:
```bash
TICKET=$(printf '%s' "<the user's message>" | grep -oE '[A-Z][A-Z0-9]+-[0-9]+' | head -1)
```
`TICKET` is opt-in only — never auto-detect from the branch name. When set, Step 2b folds
the ticket's passing requirements into the brief.

## Run flow

### Step 1: Mode + provisioning check

Resolve everything read-only up front in one block:

```bash
WORKTREE_PATH=$(pwd)
BRANCH=$(git rev-parse --abbrev-ref HEAD)
case "$WORKTREE_PATH" in
  */.claude/worktrees/*) MODE=claude-worktree ;;
  */.polyscope/clones/*) MODE=codex-clone ;;
  *)                     MODE=checkout ;;
esac
SAFE_BRANCH=$(printf '%s' "$BRANCH" | tr -c 'a-zA-Z0-9' '_' | tr '[:upper:]' '[:lower:]')
DEV_DB=$(grep -E '^DB_DATABASE=' "$WORKTREE_PATH/.env" 2>/dev/null | cut -d= -f2- | tr -d '"')
APP_URL=$(grep -E '^APP_URL=' "$WORKTREE_PATH/.env" 2>/dev/null | cut -d= -f2- | tr -d '"')
HERD_HOST=$(printf '%s' "$APP_URL" | sed -E 's#^https?://##')
echo "MODE=$MODE BRANCH=$BRANCH DEV_DB=${DEV_DB:-<unset>} APP_URL=${APP_URL:-<unset>}"
```

Carry `$MODE`, `$DEV_DB`, `$APP_URL`, `$HERD_HOST` through the run.

#### 1.provision — ensure the branch DB exists + is migrated (worktree/clone modes)

Applies to **both** `claude-worktree` and `codex-clone`. The only difference: in
`claude-worktree` the hook usually already ran (this is the idempotent fallback); in
`codex-clone` this is the primary provisioning trigger.

`.env` is authoritative for `$DEV_DB` (bootstrap length-bounds the identifier). Fall back
to derivation only if `.env` still has the default base name or is unset:
```bash
{ [ -z "$DEV_DB" ] || [ "$DEV_DB" = "{{DEV_DB_BASE}}" ]; } && DEV_DB="{{DEV_DB_BASE}}_${SAFE_BRANCH}"
```

Three conditions must all hold or the branch isn't ready: (1) `.env`'s `DB_DATABASE` is
the branch DB, (2) the branch DB exists, (3) it's **migrated** (existence alone isn't
enough — an empty DB makes seeding fail on missing tables):
```bash
DB_EXISTS=$({{DB_EXISTS_CHECK}} && echo yes || echo no)   # fill from Q3
DB_MIGRATED=no
if [ "$DB_EXISTS" = yes ]; then
  {{MIGRATE_STATUS_CMD}} >/dev/null 2>&1 && DB_MIGRATED=yes
fi
```

If any condition fails, run the **idempotent** bootstrap once (it writes `.env`
overrides, creates the DB, migrates+seeds). Don't re-implement provisioning here:
```bash
bash "$WORKTREE_PATH/.claude/hooks/bootstrap.sh"
```
If the DB exists but is unmigrated and bootstrap short-circuits, fall back:
```bash
{{MIGRATE_STATUS_CMD}} >/dev/null 2>&1 || {{MIGRATE_CMD}}
```
If it still isn't provisioned, surface bootstrap's output and stop (claude-worktree: tell
the user to restart the session; codex-clone: surface the bootstrap error directly).

If bootstrap ran, re-read the values it rewrote:
```bash
DEV_DB=$(grep -E '^DB_DATABASE=' "$WORKTREE_PATH/.env" | cut -d= -f2- | tr -d '"')
APP_URL=$(grep -E '^APP_URL=' "$WORKTREE_PATH/.env" | cut -d= -f2- | tr -d '"')
HERD_HOST=$(printf '%s' "$APP_URL" | sed -E 's#^https?://##')
```

#### 1.checkout — verify the developer's DB is ready (checkout mode only)

Don't provision; just confirm. If a check fails, **stop** — surface what to run:
```bash
[ -z "$DEV_DB" ] && { echo "DB_DATABASE not set in .env"; exit 0; }
DB_EXISTS=$({{DB_EXISTS_CHECK}} && echo yes || echo no)
[ "$DB_EXISTS" = no ] && { echo "Database '$DEV_DB' doesn't exist. Create + {{MIGRATE_CMD}} and re-trigger."; exit 0; }
{{MIGRATE_STATUS_CMD}} >/dev/null 2>&1 || { echo "Database '$DEV_DB' isn't migrated. Run {{MIGRATE_CMD}} and re-trigger."; exit 0; }
```

### Step 1a: Validate `APP_URL`

`.env` is authoritative in all modes. `$APP_URL`/`$HERD_HOST` are already resolved — just
validate:
```bash
if [ -z "$APP_URL" ]; then
  if [ "$MODE" = checkout ]; then echo "APP_URL not set in .env — set it (e.g. https://{{SITE_BASENAME}}.test) and re-trigger.";
  else echo "APP_URL not set — instance isn't fully provisioned. Re-run bootstrap (claude-worktree: restart session) and re-trigger."; fi
  exit 0
fi
echo "URL=$APP_URL HOST=$HERD_HOST DB=$DEV_DB"
```

### Step 1b: Fresh, trusted local SSL cert

The probe and browser hit the site over https. `herd secure` is idempotent; run it
defensively:
```bash
herd secure "$HERD_HOST" >/dev/null 2>&1 || echo "herd secure $HERD_HOST reported an error (continuing; probe will catch a broken cert)"
```

### Step 2: Determine scope (base branch + diff)

The branch isn't necessarily off the default branch. Determine the real base, in order:

**A. Reflog:**
```bash
git reflog show "$BRANCH" --no-abbrev 2>/dev/null | tail -1   # "branch: Created from <base>"
```
**B. Closest ancestor by distance** (candidate list from Q8):
```bash
for candidate in {{BASE_CANDIDATES}}; do
  if git rev-parse --verify "$candidate" >/dev/null 2>&1 || git rev-parse --verify "origin/$candidate" >/dev/null 2>&1; then
    ref=$(git rev-parse --verify "$candidate" 2>/dev/null || echo "origin/$candidate")
    base_sha=$(git merge-base HEAD "$ref" 2>/dev/null)
    [ -n "$base_sha" ] && echo "$(git rev-list --count "$base_sha..HEAD") $candidate $base_sha"
  fi
done | sort -n | head -1
```
**C. Upstream** as a hint: `git rev-parse --abbrev-ref --symbolic-full-name '@{u}'`.
**D. Ask** if nothing's confident.

Store and diff:
```bash
BASE_BRANCH=<determined>
BASE_SHA=$(git merge-base HEAD "$BASE_BRANCH")
CHANGED_FILES=$(git diff --name-only "$BASE_SHA"..HEAD)
```
Read these files (or their diffs). The diff is the **primary** input for the checklist.

### Step 2b: Ticket passing requirements (only if `TICKET` was passed)

Skip entirely unless `TICKET` is set. When set, fetch the issue via whatever **Jira MCP**
this harness exposes (resolve a cloud id / authenticate if the MCP needs it). Extract,
verbatim where possible: acceptance criteria / Definition of Done, the description's
explicit must-haves, subtask titles describing shippable behavior. Store as
`TICKET_REQUIREMENTS`. If the MCP is unavailable or the key doesn't resolve, **don't
block** — note "couldn't fetch `$TICKET` (reason)" for the brief's caveats and proceed
diff-anchored. Each requirement becomes a checklist item in Step 4, cross-referenced to
the diff (a requirement with no corresponding change is a 🟡 flag).

### Step 3: QA-correctness

Two paths by `$MODE`:
- **worktree/clone** (`claude-worktree` or `codex-clone`) — run 3a–3d, mutating `.env`
  and the branch DB. Track every change for the report.
- **checkout** — skip mutations; run the read-only inspection in **3.checkout**, building
  `$CAVEATS`.

```bash
CAVEATS=""
add_caveat() { CAVEATS="${CAVEATS}- $1"$'\n'; }
set_env() { # key value — replace in place, or append
  if grep -qE "^$1=" "$WORKTREE_PATH/.env"; then
    sed -i.bak -E "s|^$1=.*|$1=$2|" "$WORKTREE_PATH/.env" && rm -f "$WORKTREE_PATH/.env.bak"
  else printf '%s=%s\n' "$1" "$2" >> "$WORKTREE_PATH/.env"; fi
}
```

#### Worktree/clone mode

**3a. Ensure dev login is enabled.** The browser logs in via `{{QA_LOGIN_PATH}}`, which
requires `DEV_LOGIN_ENABLED=true` and `APP_ENV=local`:
```bash
grep -qE '^DEV_LOGIN_ENABLED=true$' "$WORKTREE_PATH/.env" || set_env DEV_LOGIN_ENABLED true
```

**3b. Project-specific correctness fixes (from Q7).** Insert the fixes Q7 surfaced for
*this* project here — each as a {detect → record changed? → apply} block, idempotent,
reported in Step 7. **If Q7 found none, this sub-step is empty — delete it.** Track a
`CORRECTNESS_CHANGED=yes|no` flag if a reseed (3d) should depend on it.
```text
# {{CORRECTNESS_FIXES}}  — or nothing
```

**3c. (reserved)** — origin project had a second correctness fix here (a PostGIS table
patch). Most projects have none; fold any second fix into 3b or delete.

**3d. Ensure seeded (only when 3b actually changed something).** Bootstrap already
seeded. A reseed is worth running only if 3b just changed state
(`CORRECTNESS_CHANGED=yes`); otherwise it's a guaranteed no-op against the same data —
skip it:
```bash
SKIPPED=""
if [ "${CORRECTNESS_CHANGED:-no}" = yes ]; then
  {{SEED_CMD}} 2>&1 | tee /tmp/qa-seed.log
  SKIPPED=$(grep -E "Skipping |Skipped |not configured" /tmp/qa-seed.log || true)
  RESEEDED=yes
else
  echo "Skipping seed — correctness was already correct; bootstrap's seed stands."
  RESEEDED=no
fi
```
If a reseed runs and `$SKIPPED` is non-empty, capture every line for the briefing's
Caveats + the report. If the seed exits non-zero, surface the error and stop. Record
every 3a–3d action for "What I changed".

#### Checkout mode (3.checkout — read-only)

Run the same checks read-only; turn each shortfall into a caveat.
```bash
grep -qE '^DEV_LOGIN_ENABLED=true$' "$WORKTREE_PATH/.env" \
  || add_caveat "checkout mode didn't enable dev login: \`{{QA_LOGIN_PATH}}\` will 404. Set \`DEV_LOGIN_ENABLED=true\` in \`.env\` and re-trigger, or QA manually."
# For each Q7 fix, inspect read-only and add_caveat on shortfall (or skip if Q7=none).
add_caveat "checkout mode does not re-seed: the QA pass runs against whatever's currently in \`$DEV_DB\`. If a checklist item needs fresh data, seed yourself and re-trigger."
```
If the dev-login caveat fires, the browser pass will fail at login — record it, continue
to write the briefing, let Step 5's probe catch the 404 and stop cleanly.

### Step 4: Build + persist the briefing

Compose from Step 2's changed files (and Step 2b's requirements if `TICKET` was passed):

```markdown
## QA plan: `<branch>`<if TICKET: ` · <TICKET>`>

**URL:** <APP_URL>{{QA_LOGIN_PATH}} — one click logs you in as the seeded user and drops you on {{POST_LOGIN_PATH}}.
**Mode:** <claude-worktree | codex-clone | checkout> — <worktree/clone: "QA-correctness applied, DB seeded" | checkout: "read-only; see Caveats">
**Base:** `<base-branch>` (detected via <reflog | merge-base distance | upstream>; <N> commits ahead)
<if TICKET: **Ticket:** `<TICKET>` — <one-line summary>>

### Ticket passing requirements
<Only if TICKET passed. Each acceptance criterion verbatim as a checkable line; note whether the diff appears to address it; flag uncovered ones 🟡. Omit section otherwise.>

### What's in the QA database
<One-line counts that anchor expectations — sample via tinker / a DB query. If a checklist item needs data the seeders don't produce, flag it: "no `recoveries` rows; item 3 requires creating one first.">

### Caveats
<worktree/clone: include only if a seeder skipped, a correctness step couldn't run, or the ticket couldn't fetch — surface each `$SKIPPED` line verbatim. checkout: include the `$CAVEATS` list. Omit only if empty.>

### QA checklist
<5–10 concrete items, each anchored to a real change and/or ticket requirement. Tell the user where to click and what to look for. See "Building a good QA checklist".>

### Edge cases worth poking at
<2–4 off-happy-path things that might be broken based on what was touched.>
```

Persist with the `Write` tool (not a heredoc) to `$WORKTREE_PATH/.claude/qa-briefing.md`,
preceded by YAML front-matter (`mode, branch, base_branch, base_sha, head_sha, url,
ticket, generated_at`). `.claude/qa-briefing.md`, `.claude/qa-agent-report.md`, and
`.claude/qa-screenshots/` should be gitignored. If the write fails, warn and continue.

### Step 5: Probe the URL

```bash
HTTP_CODE=$(curl -sS -L -o /dev/null -w "%{http_code}" -m 5 "$APP_URL{{QA_LOGIN_PATH}}" 2>/dev/null || echo "000")
```
`-L` follows the redirect to {{POST_LOGIN_PATH}}. Interpret: `200/301/302` fine; `404`
dev login didn't take (re-check `.env`, `php artisan config:clear`); `5xx` app errored —
**stop** the browser pass, tell the user to `herd restart` and re-run; `000` timeout/TLS
— if TLS, re-run `herd secure "$HERD_HOST"` then `herd restart` and re-probe before
giving up. Proceed only on 2xx/3xx (or an explained 4xx).

### Step 6: Browser pass (orchestrator plans; subagents drive)

The browser pass is **not** run inline. The orchestrator does prep, partitions the
checklist into route/feature lanes, and spawns **up to 3 parallel subagents** — one per
lane. Each returns a compact structured findings block.

#### 6a. Pre-spawn gate (orchestrator, once)
```bash
command -v agent-browser >/dev/null || echo "FAIL: agent-browser not installed"
```
If missing, don't spawn anything. Tell the user QA-correctness is applied + DB seeded,
to open `<APP_URL>{{QA_LOGIN_PATH}}` to QA manually (checklist in `.claude/qa-briefing.md`),
and how to enable the auto-walk (`brew install agent-browser && agent-browser install`).
Then go to Step 7 and mark each item "not auto-run (no browser)".

#### 6b. Baseline + reset (orchestrator, once)
```bash
LOG_FILE=$(ls -t "$WORKTREE_PATH"/{{LOG_GLOB}} 2>/dev/null | head -1)
LOG_BASELINE=0; [ -n "$LOG_FILE" ] && [ -f "$LOG_FILE" ] && LOG_BASELINE=$(wc -c < "$LOG_FILE")
mkdir -p "$WORKTREE_PATH/.claude/qa-screenshots"
rm -f "$WORKTREE_PATH/.claude/qa-screenshots"/*.png
```

#### 6c. Partition into lanes (orchestrator)
Group checklist items by route/feature affinity so one agent verifies related items in
one navigation. Cap at **3 lanes** (fold smallest together if more). Number lanes
`1..N`; lane `n` uses session `qa-agent-<n>`. Item numbers stay **global** so screenshot
filenames (`<NN>-<slug>.png`) never collide.

#### 6d. Spawn the lane subagents (orchestrator) — HARNESS-NEUTRAL

Spawn one subagent per lane, each with a self-contained prompt (the "Subagent prompt
contract" below). Use whichever mechanism this harness provides, in this preference:

1. **Claude Code** → the `Agent` tool, one call per lane **in a single message** so they
   run concurrently. `subagent_type: general-purpose`, `model: sonnet`. Each agent's
   final message **is** its findings block.
2. **Codex** → its multi-agent / sub-agent spawn (multi-agent is enabled), one sub-agent
   per lane with the same prompt; collect each one's final structured output.
3. **No parallel sub-agent mechanism** → the orchestrator runs the lanes **sequentially,
   inline**, itself — same per-lane procedure, just not parallel. (Slower, more
   orchestrator context, but correct.)

Whichever is used, the per-lane procedure, the `qa-agent-<n>` isolated-session rule, and
the return format below are identical.

#### 6e. Collect results (orchestrator)
Gather each agent's findings into per-item classifications for Step 7. If an agent
returns nothing, errors, or reports a 🔴 login failure, **don't fail the whole run** —
mark that lane's items ⚠️ "browser agent did not return findings — verify manually". A
login failure in one lane likely means all lanes failed login (shared route) — surface
prominently as 🔴.

---

### What each browser agent does

Set up an **isolated** session (unique per agent):
```bash
export AGENT_BROWSER_SESSION=qa-agent-<n>
agent-browser set viewport 1920 1080      # lock once, before first open (or right after)
```

Command surface (see `agent-browser-reference.md` in this skill dir, or
`agent-browser skills get core --full`): `open`, `snapshot -i [-c -d -s --json]`,
`find <locator> <value> <action>`, `fill`, `type`, `click`, `press`, `check`/`select`,
`scroll`/`scrollintoview`, `wait [@ref | --text | --url | --load networkidle | --fn]`,
`get url|title|text|attr|value|count`, `screenshot`, `console [--clear]`,
`network requests [--filter --clear]`, `eval [--stdin]`, `close`.

**Refs go stale** the moment the page changes (navigation, submit, any Livewire/Filament
round-trip). Re-snapshot before the next ref-based action, or use `find`.

**Log in.** Navigate to `<APP_URL>{{QA_LOGIN_PATH}}`; expect a redirect to
{{POST_LOGIN_PATH}}. Only **lane 1** writes the shared `00-landing.png`:
```bash
agent-browser open "<APP_URL>{{QA_LOGIN_PATH}}"
agent-browser get url        # expect {{POST_LOGIN_PATH}} (or under it)
agent-browser snapshot -i    # expect the authenticated shell
# lane 1 only:
agent-browser screenshot "$WORKTREE_PATH/.claude/qa-screenshots/00-landing.png"
```
If `get url` lands on a login page or the snapshot shows no authenticated shell, record a
🔴 "dev login didn't take", close the session, and **stop the lane** (return every item
unrun) — all items depend on being authenticated.

**Walk the lane's items. Be token-frugal** — verify with the lightest read that proves
the item: `find text "<header>" first`, `wait --text`, `get text @ref`, `get count
"<css>"`. Reserve a full `snapshot -i` for when you need refs to interact; then use
`snapshot -i -c` and `-s "<css>"` to keep it small. For each item:
1. Translate "where to click / what to look for" into agent-browser calls.
2. Clear buffers before the item's navigation: `agent-browser console --clear` /
   `agent-browser network requests --clear`.
3. After it settles (`wait …`, never a bare sleep): read `console`, `network requests
   --filter "$HERD_HOST"`, and a per-item `screenshot
   "$WORKTREE_PATH/.claude/qa-screenshots/<NN>-<slug>.png"`. Record only `error`-level
   console + 4xx (except expected 404s like favicon)/5xx; say "none" when clean.
4. Classify: ✅ loaded + expected element/no errors; ⚠️ loaded but couldn't locate the
   element; ❌ 5xx/console error/failed assertion; ⏭️ skipped for missing data.

**Missing-data handling.** If the briefing flags missing data ("no `recoveries` rows…"),
infer a factory: table in backticks → likely model (`recoveries` → `\App\Models\Recovery`,
singularize + PascalCase). Only attempt if `app/Models/<Model>.php` **and**
`database/factories/<Model>Factory.php` both exist; else ⏭️ "couldn't confidently infer a
factory call." When both exist, attempt **once**:
```bash
php artisan tinker --execute='\App\Models\Recovery::factory()->count(3)->create();'
```
Surface the command verbatim, re-attempt the item once, then ⏭️ if it still can't run.
Never invent factories, modify migrations, drop/truncate tables, or retry >once.

**Edge cases (best effort).** Take a brief pass at any edge cases assigned to the lane;
not graded. Skip anything needing complex UI manipulation; note "requires manual
verification."

Tear down **only this session** (never `close --all`):
```bash
agent-browser close
```

**What the agent returns** — a compact block (not raw dumps). Per item: global number +
verbatim text, classification, one-line evidence, any `error`-level console (verbatim or
"none"), any 4xx/5xx (excl. expected 404s, or "none"), the screenshot path, any factory
command attempted + outcome. End with a one-line lane summary. This block is the agent's
entire final message.

### Subagent prompt contract

Each lane prompt must be self-contained (the subagent has none of the orchestrator's
context). Include: `APP_URL`, `HERD_HOST`, `WORKTREE_PATH`, the screenshots dir; the lane
number `n` + `export AGENT_BROWSER_SESSION=qa-agent-<n>`; this lane's items **verbatim**
(global number + where-to-click + what-to-look-for) + assigned edge cases; the briefing's
"What's in the QA database" line + any missing-data note for *this lane's* items; a
pointer to read `.claude/skills/agent-qa/agent-browser-reference.md` + the "What each
browser agent does" procedure; the exact return format. Tell the agent explicitly: drive
only its lane, use only its `qa-agent-<n>` session, never `close --all`, never wipe the
screenshots dir, attempt a missing-data factory at most once per item, be token-frugal,
one screenshot per item, report only anomalies.

### Step 6g: Tail new app-log entries
```bash
[ -n "$LOG_FILE" ] && [ -f "$LOG_FILE" ] && NEW_LOG=$(tail -c "+$((LOG_BASELINE + 1))" "$LOG_FILE")
```
From `$NEW_LOG`, extract every `ERROR`/`CRITICAL`/`ALERT`/`EMERGENCY` line + ~20 lines of
any following stack trace. Empty → "clean".

### Step 7: Write the report

Write `$WORKTREE_PATH/.claude/qa-agent-report.md` **and** surface it inline.

```markdown
# Agent QA report — <branch> @ <short-sha>
_Ran against <APP_URL> in <mode> mode on <ISO timestamp> · <N> checklist items, <M> screenshots_

## Pre-QA findings summary
- 🔴 <critical/blocking — login failure, 5xx, a missing-data fix that errored>
- 🟡 <non-blocking — console warnings, slow responses, asset 404s>
- 🟢 <what worked>
(omit any empty tier)

## What I changed   <checkout mode: rename "What I observed (no mutations)">
<worktree/clone: list exactly what this run mutated — .env keys set, herd secure, any Q7 correctness, seed re-run or skipped (with skipped sub-seeders verbatim), factory fixes.>
<checkout: nothing mutated — replace with read-only inspection findings + the `$CAVEATS` list.>

## Annotated checklist
- ✅ Item 1: <verbatim> — <evidence>
- ❌ Item 2: <verbatim> — <what broke; screenshot ref; trace in log section>
- ⚠️  Item 3: <verbatim> — <loaded but couldn't locate the element>
- ⏭️  Item 4: <verbatim> — <required data; factory attempt + outcome; skipped>
- ⬜ Item 5: <verbatim> — not auto-run (no browser); verify manually

## Ticket requirements coverage
<Only if a ticket was passed. Per criterion: ✅ met (which item/evidence), ❌ not met, or 🟡 not covered by this branch. Omit otherwise.>

## Edge cases observed
<2–4 bullets, or skip.>

## App log noise (new since agent started)
<verbatim ERROR+ lines + trimmed traces in a code fence, or "clean".>

## Screenshots
- `.claude/qa-screenshots/00-landing.png`
- `.claude/qa-screenshots/01-<slug>.png`

## What to do next
<2–3 bullets prioritizing what the human should look at first.>
```

Close with: "Report written to `.claude/qa-agent-report.md`. The DB and env are left
as-is so you can dig into what I changed — ask me anything. There's nothing to tear
down."

## Building a good QA checklist
- Every item anchored to a real change or a ticket requirement — else drop it.
- When a ticket was passed, cover its passing requirements; an uncovered criterion is a
  🟡 flag, not a checklist item.
- Tell the user where to click and what to look for (actionable, not "test the widget").
- Cover happy path and branches (e.g. "admins see X, users see Y" → both).
- Don't restate the implementation. 5–10 items is the sweet spot.

## Things to be careful about
- **No teardown.** Never unlink the site or drop the DB — not ours to destroy in any mode.
- **DB/env left mutated on purpose (worktree/clone only).** Don't "clean up".
- **Checkout mode is strictly read-only.** Missing prereqs → caveats, not auto-fixes.
- **Herd may need a restart** on 5xx/000/untrusted cert — tell the user; don't do more
  than the documented `herd secure` retry.
- **Queue workers aren't running.** If an item depends on a queued side effect, mark it
  ⚠️ "depends on queue worker — verify manually after `php artisan queue:work --once`".
- **One factory-fix attempt per missing-data item.** Never truncate/drop.
- **Unique session per agent; never `close --all` mid-run.**
- **Orchestrator resets the screenshots dir once, before spawning.** Agents only write.
- **Concurrency cap = 3 lanes.**
- **Don't bypass auth differently.** The only entry point is the dev-login route. If it
  doesn't work, that's a finding — no `session()->put()` backdoor.
- **Parallel QA across worktrees/clones is fine** (each has its own URL + DB). Don't run
  two separate agent-qa passes in the *same* instance simultaneously (shared screenshots
  dir + report are wiped at the start of every run).

## What this skill does NOT do
- Doesn't create/destroy worktrees, clones, Herd links, or databases — bootstrap + the
  worktree/clone lifecycle own those in worktree/clone mode; the developer owns them in
  checkout.
- In worktree/clone mode edits only the instance's gitignored `.env`; no backup/restore.
  In checkout mode never edits `.env` — raises a caveat instead.
- Doesn't run frontend builds, restart Herd/PHP-FPM/DB (beyond the `herd secure` retry),
  commit/push/amend, or run the test suite.

## On the browser engine
Lane agents drive **Vercel's agent-browser CLI**, which launches its own local Chrome for
Testing via CDP — one per session name, so parallel agents get isolated browsers. They
run on the same machine as Herd, so they reach `APP_URL` (`https://….test`) directly —
**no tunnel**, and they never touch the user's real Chrome. A cloud-hosted browser would
need a public URL (`herd share`/cloudflared/ngrok); we deliberately don't use one.
````

---

## 4. Write `.claude/hooks/bootstrap.sh` + register the hook

Write the shared provisioning script below, filling DB/seed specifics from your profile.
Make it **executable** (`chmod +x`). Two structural rules make it dual-harness:

- **Guard accepts both instance roots** (`.claude/worktrees/*` **or**
  `.polyscope/clones/*`); no-op in the main checkout. **Do not** add a
  `git worktree list --porcelain` assertion — a Polyscope clone is a normal clone, not a
  registered worktree, so that check would wrongly abort it.
- **`.env` seeding for clones:** a fresh clone may have no `.env`. Seed from
  `$QA_ENV_SOURCE` if set, else from a discovered sibling main worktree (worktree mode),
  else fail loudly with "seed `.env` first".

```bash
#!/usr/bin/env bash
# Shared provisioning for agent-qa. Idempotent. Runs:
#   - Claude Code: automatically as a SessionStart hook (registered below)
#   - Codex/Polyscope: invoked explicitly by the agent-qa skill (no hook fires)
# No-op in the main checkout. All output to stderr.
set -uo pipefail
log() { echo "[bootstrap] $*" >&2; }

PROJECT_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
[ -z "$PROJECT_ROOT" ] && exit 0
case "$PROJECT_ROOT" in
  */.claude/worktrees/*) ;;     # Claude worktree
  */.polyscope/clones/*) ;;     # Codex/Polyscope clone
  *) exit 0 ;;                  # main checkout — never provision
esac
cd "$PROJECT_ROOT"

BRANCH="$(git branch --show-current 2>/dev/null || true)"
[ -z "$BRANCH" ] && { log "no current branch; aborting"; exit 0; }
SAFE_BRANCH="$(printf '%s' "$BRANCH" | tr -c 'a-zA-Z0-9' '_' | tr '[:upper:]' '[:lower:]')"

# Length-safe per-instance id (cap + hash long branches so they can't collide).
if [ "${#SAFE_BRANCH}" -le 40 ]; then WT_ID="$SAFE_BRANCH"
else WT_ID="$(printf '%s' "$SAFE_BRANCH" | cut -c1-33)_$(printf '%s' "$BRANCH" | shasum | cut -c1-6)"; fi

DEV_DB="{{DEV_DB_BASE}}_${WT_ID}"      # from Q2/Q3
TEST_DB="{{TEST_DB_BASE}}_${WT_ID}"    # from Q2/Q3 (drop if no testing DB)
HERD_SITE="{{SITE_BASENAME}}-${WT_ID}" # from Q1

# --- seed .env (clones may not have one) ---
if [ ! -f .env ]; then
  if [ -n "${QA_ENV_SOURCE:-}" ] && [ -f "$QA_ENV_SOURCE" ]; then
    log "seeding .env from \$QA_ENV_SOURCE"; cp "$QA_ENV_SOURCE" .env
  else
    MAIN_WT="$(git worktree list --porcelain 2>/dev/null | awk '/^worktree/ {print $2; exit}')"
    if [ -n "${MAIN_WT:-}" ] && [ -f "$MAIN_WT/.env" ] && [ "$MAIN_WT" != "$PROJECT_ROOT" ]; then
      log "seeding .env from main worktree"; cp "$MAIN_WT/.env" .env
    else
      log "no .env and no \$QA_ENV_SOURCE — seed .env first"; exit 0
    fi
  fi
fi
[ -f .env.testing ] || { [ -n "${QA_ENV_SOURCE:-}" ] && cp "$(dirname "$QA_ENV_SOURCE")/.env.testing" .env.testing 2>/dev/null || true; }

set_env() { # key value file
  if grep -qE "^$1=" "$3"; then sed -i.bak -E "s|^$1=.*|$1=$2|" "$3" && rm -f "$3.bak"
  else printf '%s=%s\n' "$1" "$2" >> "$3"; fi
}

# --- branch overrides into .env (the file Laravel loads; .env.local is inert) ---
if ! grep -q "^DB_DATABASE=${DEV_DB}$" .env; then
  log "writing branch overrides -> ${DEV_DB}"
  set_env DB_DATABASE "${DEV_DB}"                  .env
  set_env APP_URL     "https://${HERD_SITE}.test"  .env
  # {{EXTRA_ENV_OVERRIDES}} — any second-datastore DB names, etc. (Q2). Usually none.
fi
rm -f .env.local
if [ -f .env.testing ] && ! grep -q "^DB_DATABASE=${TEST_DB}$" .env.testing; then
  set_env DB_DATABASE "${TEST_DB}" .env.testing
fi

# --- create DBs if absent (fill from Q3) ---
for db in "$DEV_DB" "$TEST_DB"; do
  # {{DB_EXISTS_CHECK for $db}} || {{DB_CREATE_CMD for $db}}   ← fill from Q3
  :
done

# --- deps (guarded) ---
[ -f vendor/autoload.php ]            || { log "composer install"; composer install --no-interaction --prefer-dist >&2; }
[ -f node_modules/.package-lock.json ] || { log "npm install"; npm install >&2; }

# --- migrate + seed (fill from Q4) ---
if ! {{MIGRATE_STATUS_CMD}} >/dev/null 2>&1; then
  log "migrate + seed (dev)"
  {{MIGRATE_CMD}} >&2
  {{SEED_CMD}} >&2 || log "  (seed failed)"
  # {{EXTRA_SEED_CMDS}}   ← e.g. a permissions seeder, or nothing
fi
# (repeat for the testing DB with --env=testing if Q4 uses one)

# --- link + secure the local site ---
if ! herd links 2>/dev/null | grep -qw "$HERD_SITE"; then
  log "herd link ${HERD_SITE}"
  herd link   "$HERD_SITE"        >&2 || true
  herd secure "${HERD_SITE}.test" >&2 || true
fi
log "ready: https://${HERD_SITE}.test"
exit 0
```

**Register the Claude `SessionStart` hook** (Codex needs no registration — the skill
calls the script directly). In `~/.claude/settings.json` (user-level) or the project's
`.claude/settings.json`:
```json
{
  "hooks": {
    "SessionStart": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "[ -x \"${CLAUDE_PROJECT_DIR}/.claude/hooks/bootstrap.sh\" ] && \"${CLAUDE_PROJECT_DIR}/.claude/hooks/bootstrap.sh\" || true"
          }
        ]
      }
    ]
  }
}
```

---

## 5. Write `.claude/skills/agent-qa/agent-browser-reference.md`

Copy the cheat-sheet below near-verbatim (it's already project-neutral). Replace the
example login URL with `{{QA_LOGIN_PATH}}` and the landing with `{{POST_LOGIN_PATH}}`.

````markdown
# agent-browser command reference

Offline cheat-sheet for Vercel's **agent-browser** CLI (v0.27.0) during an agent-qa run.
For the exhaustive reference the CLI ships its own docs: `agent-browser --help`,
`agent-browser skills get core`, `agent-browser skills get core --full`.

Export an isolated session once; each parallel lane uses a **unique** name:
```bash
export AGENT_BROWSER_SESSION=qa-agent-1   # lane 1; lane 2 uses qa-agent-2, etc.
```

## Core loop (and ref staleness)
```bash
agent-browser open <url>        # 1. open a page
agent-browser snapshot -i       # 2. interactive elements, with @e1/@e2/... refs
agent-browser click @e3         # 3. act on a ref from the LATEST snapshot
agent-browser snapshot -i       # 4. re-snapshot — refs are reassigned every change
```
Refs go **stale** after any navigation, submit, Livewire/Filament re-render, modal, or
tab switch. Re-snapshot or use a semantic `find`. "Element not found: @eN" = the page
changed since you snapshotted.

## Navigating
```bash
agent-browser open <url>   ;  agent-browser back  ;  agent-browser forward
agent-browser reload       ;  agent-browser click @e3 --new-tab
```

## Reading the page
```bash
agent-browser snapshot -i           # interactive only (QA default)
agent-browser snapshot -i -c        # compact
agent-browser snapshot -i -d 3      # cap depth
agent-browser snapshot -s "#main"   # scope to a selector
agent-browser snapshot -i --json    # machine-readable
agent-browser get url|title         # confirm a redirect landed
agent-browser get text|html|value @e1 ; agent-browser get attr @e2 href
agent-browser get count ".item"     ; agent-browser is visible|enabled|checked @e1
```

## Finding (no ref)
Preference: snapshot `@eN` → semantic `find` → raw CSS.
```bash
agent-browser find role button click --name "Save"
agent-browser find text "Sign In" click [--exact]
agent-browser find label "Email" fill "user@test.com"
agent-browser find placeholder "Search" type "query"
agent-browser find testid "submit-btn" click
agent-browser find first ".card" click ; agent-browser find nth 2 ".card" hover
agent-browser click "#submit"          # raw CSS fallback
```

## Clicking / forms / scrolling
```bash
agent-browser click|dblclick|hover|focus @e1
agent-browser fill @e1 "x"   # clear+type ;  type @e1 " more"  # append
agent-browser press Enter    # Tab, Escape, Control+a, ...
agent-browser check|uncheck @e2 ; agent-browser select @e3 "value" ; agent-browser upload @e4 f.pdf
agent-browser scroll down 500 ; agent-browser scrollintoview @e7
```

## Waiting (most failures are bad waits, not bad selectors)
```bash
agent-browser wait @e5                     # until element appears
agent-browser wait --text "Success"
agent-browser wait --url "**{{POST_LOGIN_PATH}}"
agent-browser wait --load networkidle      # right answer for Livewire/Filament round-trips
agent-browser wait --load domcontentloaded
agent-browser wait --fn "!document.querySelector('[wire\\:loading]')"
```
**Anti-pattern:** bare `wait 2000`. Slow + flaky. Default command timeout 25s.

## Console / network / errors
```bash
agent-browser console [--clear]
agent-browser errors                                # uncaught page errors
agent-browser network requests [--filter <pat>] [--clear]
agent-browser network har start ; ... ; network har stop <path>
```
Finding rules: record `error`-level console (ignore warning/info unless tied to an item)
and 4xx (except expected 404s like favicon)/5xx.

## Screenshots / tabs / sessions
```bash
agent-browser screenshot <path> [--full] ; agent-browser pdf <path>
agent-browser tab ; tab new <url> ; tab 2 ; tab close 2
agent-browser close        # close THIS session (use this)
agent-browser close --all  # closes EVERY session — kills sibling lanes; never mid parallel run
```

## Settings / eval
```bash
agent-browser set viewport 1920 1080   # lock once so screenshots aren't squished
agent-browser eval "document.title"
cat <<'EOF' | agent-browser eval --stdin
document.querySelectorAll('[wire\\:loading]').length
EOF
```

## QA recipes
```bash
# Log in (dev-login bypass):
agent-browser open "<APP_URL>{{QA_LOGIN_PATH}}"   # → redirects to {{POST_LOGIN_PATH}}
agent-browser get url                              # confirm it landed
agent-browser snapshot -i                          # confirm the authenticated shell
agent-browser screenshot "$WORKTREE_PATH/.claude/qa-screenshots/00-landing.png"

# Per-item loop (scope buffers to one item):
agent-browser console --clear ; agent-browser network requests --clear
agent-browser open "<APP_URL>/<page>"
agent-browser wait --load networkidle
agent-browser snapshot -i
agent-browser console ; agent-browser network requests --filter "$HERD_HOST"
agent-browser screenshot "$WORKTREE_PATH/.claude/qa-screenshots/<NN>-<slug>.png"
```

## When something breaks
```bash
agent-browser doctor [--offline --quick]
```
"Ref/Element not found: @eN" almost always means the page changed — re-snapshot.
````

---

## 6. Adaptation checklist (confirm before you call it done)

Walk this list against the finished files; nothing below should still be a `{{PLACEHOLDER}}`:

- [ ] **Dev URL + site naming** (`{{SITE_BASENAME}}`) set in SKILL.md + bootstrap.
- [ ] **DB stack** (`{{DB_DRIVER/HOST/PORT/USER}}`, `{{DEV_DB_BASE}}`, `{{TEST_DB_BASE}}`)
      and `{{EXTRA_DATASTORES}}` resolved (most likely "none").
- [ ] **Per-branch DB** create + exists-check commands (`{{DB_CREATE_CMD}}`,
      `{{DB_EXISTS_CHECK}}`) filled in bootstrap and both Step-1 paths.
- [ ] **Migrate/seed** (`{{MIGRATE_CMD}}`, `{{SEED_CMD}}`, `{{MIGRATE_STATUS_CMD}}`,
      `{{EXTRA_SEED_CMDS}}`) filled.
- [ ] **Dev login**: route reused or built (four guards), `{{QA_LOGIN_PATH}}` +
      `{{POST_LOGIN_PATH}}` + `{{LOGIN_USER_ID}}` + `{{SESSION_PRIMING}}` correct;
      `DEV_LOGIN_ENABLED`/`APP_ENV=local` documented; `config('app.dev_login_enabled')` added.
- [ ] **Project-specific correctness** (`{{CORRECTNESS_FIXES}}`) added only if Q7 found
      real gotchas; otherwise Step 3b/3c are empty/deleted and 3d's reseed guard reflects that.
- [ ] **Base candidates** (`{{BASE_CANDIDATES}}`) and **log glob** (`{{LOG_GLOB}}`) set.
- [ ] **Three-mode detection** present; checkout mode never mutates; both worktree modes
      converge on the one `bootstrap.sh`.
- [ ] **Bootstrap guard** accepts both roots and has **no** `git worktree --porcelain`
      assertion; `.env` seeding fallback present; script is `chmod +x`.
- [ ] **SessionStart hook** registered for Claude; the skill calls bootstrap explicitly
      for Codex.
- [ ] **Fan-out** documents all three tiers (Claude `Agent`/sonnet → Codex multi-agent →
      sequential inline).
- [ ] **No origin-project leakage**: grep the finished SKILL.md + bootstrap for the
      origin's terms (its DB/schema names, its login path, its panel path). Every hit must
      be inside an `EXAMPLE`/reference block — never in an executable step.
- [ ] `.claude/qa-briefing.md`, `.claude/qa-agent-report.md`, `.claude/qa-screenshots/`
      are gitignored.

When all boxes are checked, the skill is ready. Smoke-test it: from a worktree/clone of
the target, run **"agent QA this"** and confirm it provisions, logs in, walks the
checklist, and writes `.claude/qa-agent-report.md`. From the main checkout, confirm it
runs read-only and mutates nothing.

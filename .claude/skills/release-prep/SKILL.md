---
name: release-prep
description: >
  Prep a release for QA + changelog. The USER provides the release number (e.g. "release prep
  v3.7.0"); the skill NEVER computes, guesses, or semver-bumps it. From the last released git tag to
  HEAD it builds a Jira-enriched feature/ticket inventory (DEV-#### keys parsed from commits,
  enriched via the Atlassian MCP), propagates the given release number to composer.json + a new
  config/changelog.php entry (version key + today's date), runs /document-changes to FILL IN that
  entry's change lines, then AUTHORS release-scoped QA plans itself (.context/qa-plan-agent.md +
  .context/qa-plan-human.md) in the qa-prep format — each Jira ticket a feature lane with its
  acceptance criteria as items, pre-grouped into ≤3 lanes — writes a Release Readiness Report
  (.context/release-readiness.md) flagging deploy-time gates (migrations, ClickHouse, env/config,
  flags, permissions), cross-checks that every shipped ticket is covered by the changelog (or
  deliberately excluded), confirms HEAD is the real release candidate (== origin/main), writes the
  provisioning ledger, auto-chains into insightlpr:agent-qa for browser QA (leaving human-qa for the
  user), and finally — gated on no open 🚨 blockers and a one-time confirm — creates and pushes the
  vX.Y.Z git tag to GitHub. It reuses the QA pipeline's building blocks (the
  bootstrap hook, clickhouse-qa scripts, qa-prep's plan/seeder templates) but scopes against the
  TAG, not a branch merge-base. TRIGGER when the user says "release" (or "release version" /
  "release prep" / "/release-prep" / "prep release") FOLLOWED BY a version number — e.g. "release
  v3.7.0", "release version 3.7.0", "release 3.7.0". The version number present is what marks a real
  trigger versus a casual mention of "release"; if "release" appears with NO version, do NOT auto-run
  — ask which version to prep. Never derive or bump the number. Do NOT trigger on "prep QA"/"qa prep"
  (that's qa-prep) or "document changes" (that's the changelog command alone). This skill mutates
  .env, Docker, the database, composer.json, and config/changelog.php, and (on a one-time confirm at
  the end) pushes a vX.Y.Z git tag to GitHub — if release intent is expressed any other way, ask the
  user to confirm before running.
---

# Release Prep

Turns "we're cutting a release" into a changelog bump **and** a coverage-aware QA pass that's
already running in a browser. It is the release-scoped sibling of the QA pipeline
(`qa-prep → agent-qa → human-qa → qa-complete`): where qa-prep scopes a single in-progress
branch/ticket, release-prep scopes the **whole `vX.Y.Z..HEAD` range** — many already-merged
tickets — and produces the same two plan artifacts the pipeline consumes.

## Mental model

- **Self-contained local skill; reuse, don't fork.** This skill lives in the platform repo and
  uses platform literals directly (no `<APP>_QA_*` resolver). It reuses the QA pipeline's *building
  blocks* — the bootstrap hook, the `clickhouse-qa` scripts, qa-prep's `plan-templates.md` and
  `seeder-playbook.md` formats, and `insightlpr:agent-qa` as the browser consumer — but it does
  **not** edit or re-run the plugin skills' orchestration.
- **The user owns the release number.** The number is a required argument (`release prep v3.7.0`).
  The skill **propagates** it — writes the bare number to `composer.json` `"version"` and creates a
  new `config/changelog.php` entry (`'v3.7.0' => ['date' => <today>, 'changes' => []]`) — then calls
  `/document-changes` only to fill that entry's `changes`. Neither the skill nor the command ever
  computes, guesses, or semver-bumps a version; if the user didn't give one, **ask and stop**.
- **Base is the TAG, not a merge-base.** qa-prep derives its base via reflog / merge-base, which
  collapses to HEAD on `main` (empty diff). release-prep computes its own diff from the last
  released tag: `BASE_SHA=$(git rev-list -n1 <tag>)`, `git diff <tag>..HEAD`. This is the single
  most important behavioral difference — it is why release-prep owns scoping and can't shell out to
  qa-prep for it.
- **Each Jira ticket becomes a feature lane.** The release range carries `DEV-####` keys in its
  commit messages. release-prep groups commits + changed files per ticket, enriches each with its
  Jira title + acceptance criteria, then maps each ticket to one `### F<n>:` agent lane (its ACs
  become items). Because agent-qa caps at 3 browser lanes and folds the smallest *blindly*,
  release-prep **pre-groups tickets into ≤3 lanes by surface affinity** so the partitioning is
  meaningful.
- **Ignore agent/hidden/boost noise completely.** Claude-agent files (`.claude/**`), all
  hidden/dotfiles (`.github/**`, `.junie/**`, …), and the Laravel Boost regenerated docs
  (`CLAUDE.md`, `AGENTS.md`, `boost.json`) are filtered out of the range before anything else — they
  produce no feature, no QA lane, and no changelog line. The filter (`rel_filter`) is defined in
  [`release-inventory.md`](release-inventory.md) §1.5 and applied to every file list and commit walk.
- **Regression = new stuff + existing features.** The release plan proves both: T1 new/changed
  (per-ticket lanes), T2 blast-radius (untouched surfaces sharing code with the diff), T3 core
  critical paths (the curated battery). Intensity defaults to `standard` (overridable
  `light`/`thorough`). Agent QA gets T1+T2+thin-T3 **woven into its ≤3 lanes** with the **what/why
  baked into each item's prose** (the only context its subagents receive); human QA gets the full
  T2+T3 as a `### Regression sweep`. See [`regression-strategy.md`](regression-strategy.md) +
  [`core-critical-paths.md`](core-critical-paths.md). No plugin fork — context rides in fields that
  already render.
- **The plan IS the contract with agent-qa.** agent-qa's prep gate is plan-only: it re-runs
  branch-scoped qa-prep (clobbering our release plan) only if `.context/qa-plan-agent.md` is
  missing/unparseable, or its `head_sha` ≠ `git rev-parse HEAD`, or its `wip_sha` ≠ the live
  working-tree fingerprint, or the `.env` `url`/`db` don't match the plan. release-prep writes
  matching anchors **last** (after Step 6 — the version write + `/document-changes` — settles the
  tree) and self-verifies the gate before chaining. See [`plan-authoring.md`](plan-authoring.md).
- **Dispatch `insightlpr:agent-qa` by fully-qualified name.** A second, older `agent-qa` lives at
  `.claude/skills/agent-qa/` (project copy) that ignores `.context/` plans and re-derives its own
  branch briefing. The bare name resolves to it. **Always** use `insightlpr:agent-qa`.
- **Only two tracked files change:** `composer.json` `"version"` + the new `config/changelog.php`
  entry (both written by release-prep; `/document-changes` only fills the entry's `changes`).
  Everything else (`.context/*`, `database/seeders/QaSeeder.php`) is gitignored. Surface the tracked
  changes as reviewable output; **never commit** them.
- **Satellite files** (read at the step that needs them):
  [`release-inventory.md`](release-inventory.md) — range resolution + ticket inventory + Jira
  enrichment; [`plan-authoring.md`](plan-authoring.md) — the release-scoped reading of qa-prep's
  templates, the ≤3-lane rule, AC→item rule, sticky ids, and the gate-anchor write ordering;
  [`release-readiness.md`](release-readiness.md) — the Step 5b deploy-readiness detections + report
  template; [`regression-strategy.md`](regression-strategy.md) — the blast-radius sweep, tier
  weaving, baked what/why, and the `### Regression sweep` section; [`core-critical-paths.md`](core-critical-paths.md)
  — the curated T3 "must always work" battery.

## Trigger

- **Prep a release** — triggered by **"release"** + a version number: `release v3.7.0`, `release
  version 3.7.0`, `release 3.7.0` (and the aliases `release prep` / `/release-prep` / `prep release`
  + the number). A release ticket key may follow optionally. Run the [Run flow](#run-flow) below.
- **The version number is the trigger signal.** "release" / "release version" with **no** number is
  NOT a run — ask "which version am I prepping? e.g. v3.7.0" and stop. Never guess or bump.

If release intent is expressed some other way and this skill loaded anyway, do not proceed — ask
the user for the version and confirm. ("prep QA" / "qa prep" belong to qa-prep; "document changes" is
the changelog command on its own.)

## Run flow

### Step 0: Parse the invocation + locate the target

Capture the **release number** (required; accept `v3.7.0` or bare `3.7.0`), an optional release
ticket key, and an optional **regression intensity** word:

```bash
RAW_VERSION=$(printf '%s' "<the user's message>" | grep -oiE 'v?[0-9]+\.[0-9]+\.[0-9]+' | head -1)
REL_VERSION="v${RAW_VERSION#v}"   # normalize → vX.Y.Z (the changelog key); bare = ${REL_VERSION#v}
REL_TICKET=$(printf '%s' "<the user's message>" | grep -oE '[A-Z][A-Z0-9]+-[0-9]+' | head -1)
INTENSITY=$(printf '%s' "<the user's message>" | grep -oiE '\b(light|standard|thorough)\b' | head -1 | tr '[:upper:]' '[:lower:]')
INTENSITY=${INTENSITY:-standard}   # default; drives the regression tiers (regression-strategy.md)
```

**The user owns the version.** If no version was given (`RAW_VERSION` empty), **ask** ("which
release number am I prepping? e.g. v3.7.0") and stop — never compute, guess, or semver-bump it.
`REL_VERSION` is the `vX.Y.Z` changelog key; its bare form (`${REL_VERSION#v}`) is the
`composer.json` value.

Locate `$TARGET` (same discipline as qa-prep — don't assume cwd):

```bash
case "$(pwd)" in
  */.claude/worktrees/*) MODE=worktree;       TARGET=$(pwd) ;;
  */.cursor/worktrees/*) MODE=worktree;       TARGET=$(pwd) ;;
  *)                     MODE=checkout;        TARGET=$(pwd) ;;
esac
```

`$TARGET`'s HEAD is the **release candidate** (normally `main`, or a worktree off it). From here on
target `$TARGET` explicitly (`cd "$TARGET" && …` / `git -C "$TARGET"`). Run this skill from a
worktree when you want isolated provisioning; on the main checkout it works read-mostly but
provisioning mutates the shared environment — warn and proceed only with that understood.

**Resolve `$PLUGIN_DIR` (the insightlpr plugin root) — once, here, or fail loud.** This skill
borrows the plugin's building blocks: `clickhouse-qa`'s scripts (Step 4) and qa-prep's
`plan-templates.md` / `seeder-playbook.md` (Steps 5 and 7). Resolve them through this one variable;
**never glob the plugin cache** — see the note below for why that silently broke.

```bash
PLUGIN_DIR=""
# The cache candidate uses `find`, not a glob: under zsh an unmatched glob raises
# "no matches found" (nomatch is on by default), which can abort the whole block.
CACHE_CANDIDATES=$(find "$HOME/.claude/plugins/cache/insightlpr" -mindepth 2 -maxdepth 2 -type d 2>/dev/null || true)
for c in "${CLAUDE_PLUGIN_ROOT:-}" "$HOME/.claude/skills/insightlpr" "$HOME/Herd/claude-plugins" \
         $CACHE_CANDIDATES; do
  # `if`, not an `&&` chain: a false `&&` chain is the loop body's exit status, which
  # aborts the block under `set -e` / zsh err_exit. Probe the file this skill actually
  # needs, not an unrelated marker.
  if [ -n "$c" ] && [ -x "${c%/}/skills/clickhouse-qa/scripts/up.sh" ]; then PLUGIN_DIR="${c%/}"; break; fi
done
[ -n "$PLUGIN_DIR" ] || { echo "FATAL: insightlpr plugin not found (tried \$CLAUDE_PLUGIN_ROOT, ~/.claude/skills/insightlpr, ~/Herd/claude-plugins, and the plugin cache). REFUSING to run — ClickHouse provisioning and the qa-prep plan/seeder templates would be silently skipped."; exit 1; }
export PLUGIN_DIR
echo "PLUGIN_DIR=$PLUGIN_DIR"
```

If that guard exits, STOP and tell the user to fix the plugin path — do not continue. A run
without `clickhouse-qa`'s scripts leaves `.env` pointing at whatever it already held (potentially
**ClickHouse Cloud**) while the plans claim a local QA container.

> **Why the cache glob was wrong** (fixed 2026-08-13): three call sites resolved the plugin via
> `~/.claude/plugins/cache/insightlpr/insightlpr/*/…`. The `insightlpr` marketplace is registered
> in `~/.claude/settings.json` as a **directory source** pointing at `~/Herd/claude-plugins`, so
> **no cache copy is ever created** and that glob matched nothing. `S=$(ls -d … | head -1)` then
> yielded an empty string, making `bash "$S/status.sh"` run `bash /status.sh`. Same failure class
> as DEV-2104/2105: an unresolved plugin path skips a whole layer without ever saying so.
> `~/.claude/skills/insightlpr` is a symlink to `~/Herd/claude-plugins` (same inode — edits are
> live), which is why it is the first stable candidate. The cache glob is kept **last**, so a
> future github-source install still resolves.

### Step 1: Resolve the release range

The **base** is the last released tag (auto-detected — for the range only, not the new number). The
new release number is `$REL_VERSION` (from Step 0, user-supplied).

**1a. Confirm HEAD is the real release candidate** (cheap safety gate — don't prep a stale/divergent
tree). Fetch and compare local HEAD to `origin/main`:

```bash
git -C "$TARGET" fetch origin main --tags --quiet
HEAD_SHA=$(git -C "$TARGET" rev-parse HEAD)
ORIGIN_MAIN=$(git -C "$TARGET" rev-parse origin/main)
AHEAD=$(git -C "$TARGET" rev-list --count origin/main..HEAD)
BEHIND=$(git -C "$TARGET" rev-list --count HEAD..origin/main)
echo "head=$HEAD_SHA origin/main=$ORIGIN_MAIN ahead=$AHEAD behind=$BEHIND"
```

- `ahead=0 behind=0` → HEAD **is** `origin/main`; good.
- `behind>0` → 🚨 the candidate is **stale** (main moved past you); stop and tell the user to
  rebase/pull — you'd be prepping (and later tagging) an old commit.
- `ahead>0` → 🚨 local commits aren't on `origin/main` yet; warn — the release won't contain them
  until pushed/merged. Confirm before continuing.

  Record the verdict for the readiness report's 🚨 section and for the Step 10c tag gate (the tag
  must point at `origin/main`).

**1b. Resolve the range.**

```bash
# Last RELEASED tag for the range base. If the latest tag is our own new number (a warm re-run
# already created it), step back to the previous tag so the range isn't empty.
TAG=$(git -C "$TARGET" describe --tags --abbrev=0)
[ "$TAG" = "$REL_VERSION" ] && TAG=$(git -C "$TARGET" describe --tags --abbrev=0 "${REL_VERSION}^" 2>/dev/null)
BASE_SHA=$(git -C "$TARGET" rev-list -n1 "$TAG")
COMMITS=$(git -C "$TARGET" rev-list --count "$TAG"..HEAD)
# rel_filter (see release-inventory.md §1.5) drops Claude-agent files, hidden/dotfiles, and the
# Boost-regenerated docs so they never count as release changes.
rel_filter() { grep -vE '(^|/)\.' | grep -vxE 'CLAUDE\.md|AGENTS\.md|boost\.json'; }
ALL_FILES=$(git -C "$TARGET" diff --name-only "$TAG"..HEAD | wc -l | tr -d ' ')
FILES=$(git -C "$TARGET" diff --name-only "$TAG"..HEAD | rel_filter | wc -l | tr -d ' ')
echo "base=$TAG new=$REL_VERSION range=$TAG..HEAD commits=$COMMITS files=$FILES (ignored=$((ALL_FILES - FILES)))"
```

If `$COMMITS` is 0, stop — nothing to release since `$TAG`. If `git describe` finds no tag, ask the
user for the baseline tag and stop. Sanity-check that `$REL_VERSION` is greater than `$TAG`; if it
isn't (already-released or older), warn the user and confirm before continuing — but still don't
change the number yourself.

### Step 2: Build the feature/ticket inventory + blast radius

Read [`release-inventory.md`](release-inventory.md) and follow it: parse `DEV-####` keys from each
commit in `$TAG..HEAD`, group commits + their changed files per ticket, and collect a final
`Unticketed` bucket for keyless commits. Carry the changed-file set per ticket forward — it drives
the scope classification, the seeder, and the regression tiers.

Then run the **blast-radius sweep** (T2) per [`regression-strategy.md`](regression-strategy.md): for
the changed *shared* symbols (models, services, ClickHouse query classes, Filament base/traits,
Blade components/layouts), grep for **untouched** referrers and map them to the user-facing surfaces
a release could regress without touching directly. Bound to one hop; cap to the top surfaces by
referrer count and `log()` what's dropped. **Skip under `light`.**

### Step 3: Jira enrichment

Per [`release-inventory.md`](release-inventory.md): for each `DEV-####`, fetch the ticket title and
acceptance criteria via the Atlassian MCP (deferred tools — load schemas via ToolSearch first:
`select:mcp__*__getJiraIssue` / `searchJiraIssuesUsingJql`). Number ACs `DEV-XXXX/AC-1`,
`DEV-XXXX/AC-2`, …. On any MCP failure for a key, `add_caveat "couldn't enrich <key> (<reason>)"`
and fall back to a diff-anchored lane for it (title from the commit subject; items from the diff).

### Step 4: Verify / provision the environment

release-prep uses platform literals (this is a platform-local skill). Reuse the pipeline's building
blocks — see qa-prep's SKILL.md Steps 1b–5 for the mechanics; the platform-relevant pieces:

```bash
PG_HOST=127.0.0.1; PG_USER=root
DEV_DB=$(grep -E '^DB_DATABASE=' "$TARGET/.env" | cut -d= -f2- | tr -d '"')
APP_URL=$(grep -E '^APP_URL=' "$TARGET/.env" | cut -d= -f2- | tr -d '"')
HERD_HOST=$(printf '%s' "$APP_URL" | sed -E 's#^https?://##')
CAVEATS=""; add_caveat() { CAVEATS="${CAVEATS}- $1"$'\n'; }
```

1. **Bootstrap verify (worktree).** If `$DEV_DB` is empty or the bare shared `zod`, or the DB is
   missing/unmigrated (`php artisan migrate:status`), run `bash .claude/hooks/bootstrap.sh` once
   (idempotent — no-ops when healthy), then re-read `DEV_DB`/`APP_URL`. On the main checkout, do
   **not** bootstrap; verify only and caveat anything missing.
2. **Dev login + queue + cert.** Ensure `DEV_LOGIN_ENABLED=true` and `APP_ENV=local`. Set
   **`QUEUE_CONNECTION=sync`** in the worktree `.env` (via qa-prep's `set_env` replace-or-append
   helper) so queued side-effects — notifications, webhooks — run **inline during the request** and
   are QA-observable without a worker. Record `QUEUE_CONNECTION` in the ledger `env_keys_set`, note
   `Queue: sync (inline)` in both plans' Environment block, and `add_caveat "queue runs inline
   (sync) for QA — the real redis-cluster / Horizon path is NOT exercised; that's a deploy concern,
   see the readiness report."` Run `herd secure "$HERD_HOST"` defensively (idempotent).
3. **Local ClickHouse via clickhouse-qa** (worktree). The scripts dir comes from the `$PLUGIN_DIR`
   resolved in Step 0 — **do not re-derive it here, and never glob the plugin cache** (that glob
   matched nothing and silently ran `bash /status.sh`; see Step 0's note):
   ```bash
   S="$PLUGIN_DIR/skills/clickhouse-qa/scripts"
   [ -x "$S/up.sh" ] || { echo "FATAL: clickhouse-qa scripts missing at $S"; exit 1; }
   export QA_CH_CONTAINER=clarity-qa-clickhouse QA_CH_HTTP_PORT=18123 \
     QA_CH_SCHEMA_PATH=database/schema/clickhouse-schema.sql \
     QA_CH_DB_CLARITY_PATTERN='clarity_{wt}' QA_CH_DB_ZOD_PATTERN='zod_{wt}'
   docker info >/dev/null 2>&1 || { open -a Docker 2>/dev/null; for i in $(seq 1 12); do sleep 5; docker info >/dev/null 2>&1 && break; done; }
   (cd "$TARGET" && bash "$S/status.sh") || (cd "$TARGET" && bash "$S/up.sh")
   ```
   Assert `CLICKHOUSE_HOST=127.0.0.1` after. **Local Docker ClickHouse is the expected provider for a
   release** — the regression battery deliberately exercises CH-heavy surfaces (Dashboard stats, Kit
   User Path heatmaps, Hit Density, Client Analytics), so they must run against real CH semantics
   (FINAL dedup, CH SQL functions, MV fan-out), and the seeder loads real CH rows via `seed.sh`. So
   try hard to get Docker up (Step 3a's daemon poll). If Docker truly won't start, the pgsql bridge
   fallback (`CLICKHOUSE_DRIVER=pgsql`, host `127.0.0.1`, port `5432`; `php artisan config:clear`)
   keeps the pass alive but is a **prominent release caveat, not a silent one** — those CH-backed
   regression items can't be trusted, so surface "ClickHouse on the pgsql bridge — start Docker and
   re-run `release $REL_VERSION` for real CH regression coverage" 🚨 in the readiness report AND the
   Step 11 summary. `CH_STRATEGY` ∈ {`docker` (expected), `pgsql-bridge` (degraded — flagged),
   `none`}.
4. **tiger.state PostGIS patch** (worktree; idempotent — required before any `Scan`-inserting
   seed). Apply qa-prep Step 4's heredoc (`ADD COLUMN IF NOT EXISTS the_geom / simplified_geom /
   geom_4326` on `tiger.state`) against `$DEV_DB`. Record `caps.postgis_patch=applied`.

### Step 5: Compose + run the release QaSeeder

Read qa-prep's seeder playbook — the plugin owns the canonical rules, at
`$PLUGIN_DIR/skills/qa-prep/seeder-playbook.md` (Step 0 resolved `$PLUGIN_DIR`) — and compose
`database/seeders/QaSeeder.php` scoped to the **union of all tickets** in the range, not
one ticket:

- One pro org + one non-pro org; collapse personas to the minimal set covering the roles the
  release actually touched (`qa-%@tester.com`, password `password`).
- **Reachability for gated features** — put the QA pro-org on the tier(s) the release's features AND
  the `core-critical-paths.md` Pro rows require (write `platform_repo_pro` / tier to its `org_meta`,
  per `Org::isProEnabled()` / `ProTierEnum`), and flip any experimental `*_ENABLED` config the items
  need. Otherwise agent-qa lands on an empty screen and "passes." (Reachability ≠ changelog
  announcement — gated features are QA'd, just not documented.)
- Seed enough data for the **T2/T3 regression surfaces** too, not just the new features — a
  blast-radius or core-battery item needs rows to exercise (e.g. hotlists to filter, hits to list).
- Seed only the surfaces in scope — paginate only list views that changed or are in the battery; add
  boundary records only for thresholds the release moved.
- `setPermissionsTeamId($org->id)` before any `assignRole`; everything `firstOrCreate`-guarded;
  deterministic `QA — ` record labels.
- Run it: `cd "$TARGET" && php artisan db:seed --class=QaSeeder --force`. Under `docker`, author CH
  rows to `.context/qa-ch-seed.sql` (bare table names, `_peerdb_is_deleted=0`, explicit version
  cols, UTC, varied per re-run) and run `bash "$S/seed.sh" --db clarity "$TARGET/.context/qa-ch-seed.sql"`.
- Ensure ignored: append `database/seeders/QaSeeder.php` and `.context/` to
  `$(git -C "$TARGET" rev-parse --git-common-dir)/info/exclude` if absent.

### Step 5b: Release readiness scan

Read [`release-readiness.md`](release-readiness.md) and run it: a read-only static scan over the
`$TAG..HEAD` diff (through `rel_filter`) plus cheap cross-checks against the provisioned env, written
to `.context/release-readiness.md`. It surfaces the deploy-time work the diff implies — pending
migrations (🚨 kamal does **not** auto-run them), ClickHouse table refs needing a chlocal/CH-Cloud
schema apply (🚨 cross-repo, deploy-order), new no-default `env()` keys (🚨 must exist before
`config:cache`), new Pennant flags/tiers (⚠️ rollout decision), new permissions/Filament resources
(⚠️ `app:seed-permissions` not in deploy), schedule/queue/dependency changes (ℹ️), post-deploy
backfill commands (⚠️), and the CI gate (🚨). It is advisory — never blocks or runs deploy actions —
but 🚨 gates are echoed in the Step 11 summary. This scan does not touch the working tree, so its
placement relative to Step 6 doesn't affect the gate.

### Step 6: Propagate the release number + document changes — BEFORE authoring plans

The skill owns the version number (the user gave it); the command owns only the change prose.

1. **Write the version (release-prep).** Set `composer.json` `"version"` to the bare number
   (`${REL_VERSION#v}`, e.g. `3.7.0`), and prepend a new entry to the top of `config/changelog.php`'s
   `'releases'` array, mirroring the existing shape:
   ```php
   '<REL_VERSION>' => [
       'date' => '<today — Month Dayth, Year, matching existing entries>',
       'changes' => [],
   ],
   ```
   No `composer install` (the `version` field doesn't affect the lock).
2. **Fill the changes (`/document-changes`).** Invoke the command — it documents the `$TAG..HEAD`
   changes into that topmost entry's `changes` array (modeled on existing entries; honors the
   gated-feature exclusions). It never touches the version key, date, or `composer.json`.
3. **Idempotency.** On a warm re-prep, if the `$REL_VERSION` entry already exists, don't add a second
   — re-fill its `changes` in place.
4. **Changelog coverage cross-check.** After the entry is filled, assert that every ticket in the
   Step 2/3 inventory is accounted for: each `DEV-####` either appears in the new entry's `changes`
   (or a recent prior entry), OR is deliberately not announced — gated (Pennant/config/tier — the
   gated-feature rule), internal/non-user-facing, or noise. List any **uncovered** tickets with a
   one-line "why excluded" (or "MISSING — should this be in the changelog?") so a shipped-but-
   undocumented feature can't slip through. Surface this in the Step 11 summary; it never edits the
   changelog itself (you decide whether to add a line) — it just flags gaps.

This step **must precede** Step 7 — its edits land in the working tree, so the `head_sha`/`wip_sha`
gate anchors must be computed *after* it (Step 7), or agent-qa would see drift and re-prep.

### Step 7: Author both QA plans

Read [`plan-authoring.md`](plan-authoring.md) + [`regression-strategy.md`](regression-strategy.md)
and compose `.context/qa-plan-agent.md` + `.context/qa-plan-human.md` against qa-prep's plan
templates at `$PLUGIN_DIR/skills/qa-prep/plan-templates.md` (Step 0 resolved `$PLUGIN_DIR`).
Release-scoped specifics:

- **Release brief preamble** (both plans): what's shipping (feature/ticket list, one-line what+why
  from Jira), the shared surfaces touched, the regression-risk areas, and the dual goal — *verify
  new (T1) AND confirm existing unaffected (T2/T3)*. human-qa renders it; agent-qa reads it into its
  report (it doesn't reach the browser subagents — that's why per-item prose carries the why).
- **Agent plan:** `base_branch: <tag>`, `base_sha` = tag SHA, `scope: both` (from the union diff),
  each pre-grouped lane an `### F<n>:` section listing its tickets + AC-derived items. **Weave the
  T2 blast-radius + thin-T3 smoke into the matching surface lanes** (no 4th lane) and **bake the
  what/why into each item's prose** (`A4. [T2 · shares Hotlist model w/ DEV-1948] confirm filter +
  CSV export unchanged — look for: …`). `### B:` aggregates touched backend classes across the range.
- **Human plan:** the feature-by-feature happy-path tour (T1) + a dedicated **`### Regression
  sweep`** section (`rg-<n>` ids) carrying the full T2 + T3 battery per `$INTENSITY`, each step framed
  *"should be UNCHANGED"* with the what-changed context in `action`/`look for`.

Reuse sticky item ids (`A`/`B`/`hp`/`ec`/`rg`) from any existing plan. **Compute `head_sha` and
`wip_sha` here, last** (after Step 6 settled):

```bash
HEAD_SHA=$(git -C "$TARGET" rev-parse HEAD)
WIP_SHA=$({ git -C "$TARGET" diff HEAD; git -C "$TARGET" ls-files --others --exclude-standard; } | git hash-object --stdin)
```

Write `url:`/`db:` verbatim from `.env` (the gate cross-checks them). Backfill `seeded:` + personas
after Step 5.

### Step 8: Write the provisioning ledger

`Write` `.context/qa-prep-ledger.json` in qa-prep's Step-10b schema (so agent-qa reads the login
spec and qa-complete can close out). Set `qa_log[0].stage = "release-prep"`, `event: "prepped"`,
`details` = the tag range + ticket count + scope + ch_strategy + seed summary; `ticket` = `$REL_TICKET`
or null; full `caps`/`login`/`config` blocks with platform defaults (`login.type=dev-login`,
`url_pattern=/__qa/login/{id}`; guard prefixes `zod_` / `zod_testing_` / [`clarity_`,`zod_`]; site
`clarity-{wt}.test`). On a warm re-prep, preserve the existing `qa_log` and append.

### Step 9: Self-verify the gate (safety interlock)

Before chaining, recompute the anchors and run agent-qa's exact gate check against the plan we just
wrote:

```bash
PLAN="$TARGET/.context/qa-plan-agent.md"
HEAD_NOW=$(git -C "$TARGET" rev-parse HEAD)
WIP_NOW=$({ git -C "$TARGET" diff HEAD; git -C "$TARGET" ls-files --others --exclude-standard; } | git hash-object --stdin)
PLAN_SHA=$(grep -m1 -E '^head_sha:' "$PLAN" | awk '{print $2}')
PLAN_WIP=$(grep -m1 -E '^wip_sha:' "$PLAN" | awk '{print $2}')
ENV_URL=$(grep -E '^APP_URL=' "$TARGET/.env" | cut -d= -f2- | tr -d '"')
PLAN_URL=$(grep -m1 -E '^url:' "$PLAN" | awk '{print $2}')
[ "$PLAN_SHA" = "$HEAD_NOW" ] && [ "$PLAN_WIP" = "$WIP_NOW" ] && [ "$ENV_URL" = "$PLAN_URL" ] \
  && echo "GATE=pass" || echo "GATE=FAIL"
```

On `GATE=FAIL`, something touched the tree after Step 7 — rewrite the anchors (and `url`/`db` if
drifted) and re-check. Do not chain until `GATE=pass`; otherwise agent-qa would re-run
branch-scoped qa-prep and clobber the release plan.

### Step 10: Auto-chain into agent QA

Announce the hand-off and invoke the **`insightlpr:agent-qa`** skill by its fully-qualified name
(never bare `agent-qa`). It will find `GATE=pass`, consume our release plan, fan its `### F` lanes
across Sonnet subagents, run the `### B:` backend lane read-only, and write results back. Capture
its 🔴 blocking findings — they feed the Step 10c tag gate.

### Step 10c: Create + push the release tag (gated, confirmed)

The final act of prep: tag the release candidate on GitHub. **Gated** — only offer the tag when all
of these hold; otherwise print why and skip (the prep artifacts still stand):

1. **No open 🚨 blockers** — the readiness scan's 🚨 gates are resolved/acknowledged AND agent-qa
   reported no 🔴 blocking findings. If any remain, do **not** tag — list them and stop here.
2. **HEAD is still the release candidate** — re-check `HEAD == origin/main` and that HEAD hasn't
   moved since Step 1 (`git fetch origin main` then compare). The tag must point at `origin/main`.
3. **The tag doesn't already exist** — `git -C "$TARGET" rev-parse -q --verify "refs/tags/$REL_VERSION"`
   and `git ls-remote --tags origin "$REL_VERSION"`. If it exists locally or on the remote, do not
   re-tag or move it — report it and stop (moving a published tag is destructive).

Then **show exactly what will happen and ask once** (this is an outward-facing, hard-to-undo push):

> Tag `v3.7.0` → `<short-sha>` (origin/main) and push to GitHub? Human QA hasn't run yet.

On an explicit yes:

```bash
git -C "$TARGET" tag -a "$REL_VERSION" -m "Release $REL_VERSION" "$HEAD_SHA"
git -C "$TARGET" push origin "$REL_VERSION"
```

Use an **annotated** tag. Never `-f`/`--force`, never delete or move an existing tag. On no/silence,
skip and print the two commands for the user to run later. Record the outcome (tagged+pushed / skipped
+ reason) in the ledger `qa_log` (`stage: "release-prep"`, `event: "tagged"` or `"tag-skipped"`) and
in the Step 11 summary.

Tell the user human-qa is still theirs: "run `human QA this` when you're ready for the manual pass."

### Step 11: Release summary

Close with a compact message:

- **Range:** `<tag>..HEAD` — N commits, M product files (X ignored: Claude-agent/hidden/boost), K
  tickets (+ unticketed count).
- **Inventory table:** ticket → Jira title → files touched → AC count (mark Jira-unenriched rows).
- **Version:** `$REL_VERSION` (your number) written to `config/changelog.php` + `composer.json`,
  changes documented into that entry — *not committed; review it.*
- **Changelog coverage:** "all N tickets covered" — or list the uncovered ones with why
  (gated/internal) / "MISSING — consider documenting".
- **Release candidate:** HEAD == origin/main (or the ahead/behind warning).
- **Readiness:** the 🚨 gates from `.context/release-readiness.md`, verbatim and up top (pending
  migrations, ClickHouse/chlocal schema, required env keys, CI status) + a pointer to the full report
  for ⚠️/ℹ️ items.
- **Plans:** both paths, lane count, scope.
- **Regression:** intensity (`$INTENSITY`), the T2 blast-radius surfaces covered, and the T3 core
  battery count (+ note if Scout battery folded in).
- **Environment:** site + probe, Postgres `$DEV_DB`, ClickHouse `$CH_STRATEGY`, seed summary.
- **Tag:** `$REL_VERSION` tagged + pushed to GitHub / skipped (+ reason — open blocker, HEAD moved,
  already exists, or declined).
- **Caveats:** every `add_caveat` line verbatim (queue-worker restart if CH env changed, any Jira
  miss, bridge fallback).
- **Next:** agent-qa has run; "review the changelog + readiness, then `human QA this` for the manual
  pass."

## Things to be careful about

- **Gate-anchor ordering is load-bearing.** Author plans (and compute `head_sha`/`wip_sha`) only
  after Step 6 (version write + `/document-changes`) has finished. The Step 9 interlock is the backstop.
- **FQ name only.** `insightlpr:agent-qa`, never `agent-qa`.
- **Never invent the version.** The user supplies `$REL_VERSION`; if it's missing, ask and stop. The
  skill propagates it; `/document-changes` never touches it.
- **Never commit the version/changelog change** — that's the operator's call.
- **Tagging is gated, confirmed, and non-destructive.** Only at the very end (Step 10c), only with no
  open 🚨 blockers, only pointing at `origin/main`, only after an explicit yes. Never `-f`, never move
  or delete an existing tag, never re-tag a version that already exists locally or on the remote.
- **No teardown here.** Sites, DBs, the CH container, the seeder, and plans are left in place for
  agent-qa and human-qa. Teardown belongs to `qa-complete` (or the worktree lifecycle).
- **Main-checkout mode mutates shared state.** Prefer a worktree off the release candidate so
  provisioning is isolated; if run on the main checkout, only proceed having warned the user.
- **The seeder must stay idempotent** — it's re-run mid-QA; vary CH values across runs (insert-dedup
  drops identical blocks).

## What this skill does NOT do

- It doesn't tag until the end, unprompted, or past a blocker — the tag (Step 10c) is gated on a
  clean readiness/agent-qa state and a one-time confirm; it never force-moves or re-tags.
- It doesn't decide the version number — the user supplies it; the skill only propagates it.
- It doesn't deploy or create a GitHub Release object — it pushes the tag; deploy is the existing
  GHA/Kamal flow, the human's call.
- It doesn't run migrations, apply ClickHouse schema, set production env, or roll out flags — the
  readiness report flags those as deploy-time work; it never performs them.
- It doesn't drive a browser or render the human guide — agent-qa and human-qa own those.
- It doesn't run the test suite — CI does that.
- It doesn't fork or edit the insightlpr plugin skills — it reuses their building blocks.

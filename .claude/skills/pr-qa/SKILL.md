---
name: pr-qa
argument-hint: "<PR#> | complete <PR#>"
description: >
  Human-QA a "finished" ticket by its GitHub PR number. `/pr-qa 469` spins up (or switches to) the
  PR's own QA worktree at .claude/worktrees/pr-<N>, pulls the PR tip, provisions the Herd .test
  site + branch Postgres DB + per-worktree Docker ClickHouse, seeds, refreshes the human QA plan
  from the PR's committed docs/investigations/<KEY>/ artifacts + fresh Jira ACs, and opens the
  interactive human QA guide. `/pr-qa complete 469` takes the guide's "Copy results as Markdown"
  export, drafts a PR comment for approval, posts it with gh, then tears the whole environment
  down (DBs, ClickHouse, Herd link, worktree, local branch). Never implements, reviews, or runs
  agent-qa. Orchestrates insightlpr:qa-prep → refresh → insightlpr:human-qa, and
  insightlpr:qa-complete (teardown-only) on complete. TRIGGER on `/pr-qa <N>`,
  `/pr-qa complete <N>`, "PR QA <N>", or "PR QA complete <N>" (case-insensitive; <N> may be 469,
  #469, or a PR URL). Do NOT trigger on "QA this PR", "test this PR", "human QA this", or "set up
  QA" — ask the user to confirm with `/pr-qa <N>` instead. Mutates .env, starts Docker,
  creates/seeds/drops databases, and (after an explicit approval) posts a PR comment.
---

# PR QA

- `/pr-qa 469` → set up (or switch to) PR #469's QA env, seed it, open the guide.
- `/pr-qa complete 469` → post the human QA results on the PR, then tear everything down.

## Mental model

- **One worktree per PR, owned by this skill:** `.claude/worktrees/pr-<N>`. It uses the PR's real
  branch when that branch is free locally, otherwise a mirror branch `qa/pr-<N>` (PR branches are
  often checked out in Cursor fleet worktrees). Never touch Cursor worktrees, `/ticket`
  worktrees, the session's own worktree, or the main checkout.
- **The QA worktree is read-only.** Nothing is committed or pushed from it; fixes go through the
  PR's own flow.
- **The insightlpr plugin is the engine.** `insightlpr:qa-prep` provisions, seeds, and writes the
  plans + ledger; `insightlpr:human-qa` renders the guide; `insightlpr:qa-complete`
  (`QA_TEARDOWN_ONLY=1`) tears down what the ledger recorded. This skill resolves the PR, owns the
  worktree, runs the refresh pass, and posts the PR comment.
- **The committed `docs/investigations/<KEY>/qa-prep-ledger.json` belongs to the fleet** (version
  2, Linux box). Never treat it as the local ledger, tear it down, or edit it.
- **State file:** `.context/pr-qa.json` in the worktree —
  `{pr, key, branch, branch_kind, base_ref, head_oid, url, wt}`. Setup writes it; complete reads it.

## Parse the arguments

First token `complete` → [Complete](#complete--pr-qa-complete-n); otherwise [Setup](#setup--pr-qa-n).
`N` = the digits of `469`, `#469`, or `…/pull/469`. No number → ask which PR and stop.

## Setup — `/pr-qa <N>`

### 1. Resolve the PR

```bash
gh pr view "$N" --repo insight-lpr/platform \
  --json number,title,body,state,isDraft,headRefName,headRefOid,baseRefName,url,isCrossRepository
```

- `isCrossRepository: true` → refuse.
- `state` MERGED or CLOSED → ask before continuing.
- `KEY` = first case-insensitive `DEV-[0-9]+` in the title, then `headRefName`, then the body;
  upper-cased. No match → ticketless.

Keep `HEAD_REF`, `HEAD_OID`, `BASE_REF`, `PR_URL`, `KEY`.

### 2. Worktree — switch or create

```bash
MAIN=$(git worktree list --porcelain | awk '/^worktree/ {print $2; exit}')
WT="$MAIN/.claude/worktrees/pr-$N"
git -C "$MAIN" fetch origin "$HEAD_REF" "$BASE_REF"
```

**`$WT` exists → switch + pull.**

```bash
BR=$(jq -r '.branch // empty' "$WT/.context/pr-qa.json" 2>/dev/null)
[ -n "$BR" ] || BR=$(git -C "$WT" branch --show-current)
PREV_OID=$(jq -r '.head_oid // empty' "$WT/.context/pr-qa.json" 2>/dev/null)
git -C "$WT" status --porcelain
git -C "$WT" rev-list --count HEAD --not "origin/$HEAD_REF" ${PREV_OID:+"$PREV_OID"}
```

- Dirty tree, or a non-zero count (local commits that were never the PR's) → stop and ask.
- `git -C "$WT" merge-base --is-ancestor HEAD "origin/$HEAD_REF"` → `git -C "$WT" merge --ff-only "origin/$HEAD_REF"`.
- Otherwise the PR was force-pushed → `git -C "$WT" reset --hard "origin/$HEAD_REF"` and tell the user.

**`$WT` missing → create.** Use the real branch only when no local branch of that name exists and
it isn't checked out anywhere — an existing local branch's reflog says "Created from
origin/<head>", which makes qa-prep scope an empty diff.

```bash
if git -C "$MAIN" show-ref --verify -q "refs/heads/$HEAD_REF"; then
  BR="qa/pr-$N"; KIND=mirror
else
  BR="$HEAD_REF"; KIND=real
fi
git -C "$MAIN" show-ref --verify -q "refs/heads/$BR" && git -C "$MAIN" branch -D "$BR"   # leftover mirror only

# Created from the BASE first: qa-prep Step 6a reads the base from the branch's oldest
# reflog entry, so "Created from origin/<base>" scopes the real PR diff.
git -C "$MAIN" worktree add -b "$BR" "$WT" "origin/$BASE_REF"
git -C "$WT" reset --hard "origin/$HEAD_REF"
git -C "$WT" branch --set-upstream-to "origin/$HEAD_REF"

cp -Rc "$MAIN/vendor" "$WT/vendor"
cp -Rc "$MAIN/node_modules" "$WT/node_modules"
cmp -s "$MAIN/composer.lock" "$WT/composer.lock" || (cd "$WT" && composer install --no-interaction)
```

Copy (APFS clone), never symlink — the classmap breaks. The `branch -D` line can only hit
`qa/pr-<N>` (a real `$HEAD_REF` never reaches it, by the `if` above).

**Both paths:** assert `git -C "$WT" rev-parse HEAD` == `HEAD_OID` (stop if not), then write
`$WT/.context/pr-qa.json` with `pr`, `key`, `branch` (`$BR`), `branch_kind` (keep the existing
value on a switch), `base_ref`, `head_oid`, `url`, `wt`.

### 3. Switch the session in

Call **`EnterWorktree` with `path: $WT`** and confirm `pwd` is `$WT`. Required: qa-prep and
human-qa pick their target from `pwd`, so from anywhere else they'd provision the wrong checkout.

### 4. Stale-context guard

If `.context/qa-prep-ledger.json` `.ticket` ≠ `KEY`, or `.context/qa-plan-human.md` frontmatter
`ticket:` ≠ `KEY`:

```bash
TS=$(date +%Y%m%d%H%M%S); mkdir -p ".context/_stale-$TS"
find .context -mindepth 1 -maxdepth 1 ! -name '.gitkeep' ! -name '_stale-*' ! -name 'pr-qa.json' \
  -exec mv {} ".context/_stale-$TS/" \;
```

### 5. Setup + seed (qa-prep)

**Pre-flight — leftover literal CH databases.** Another worktree can leave plain `clarity` / `zod`
databases in the shared container. `up.sh`'s leak check then dies right *after* a successful schema
apply; a second `up.sh` run skips the apply and passes. Check first:

```bash
docker exec clarity-qa-clickhouse clickhouse-client -q \
  "SELECT name FROM system.databases WHERE name IN ('clarity','zod')" 2>/dev/null
```

Any rows → tell the user they exist (never drop them — they aren't ours) and pass the hint below.

Invoke **`insightlpr:qa-prep`** by name with:

- `QA_PROVISION_CH=yes` — always the Docker ClickHouse, per-worktree DBs (never literal
  `clarity`/`zod`).
- `QA_PREP_CONTEXT_PATH=docs/investigations/<KEY>/prep-context.json` — only when that file exists.
- The message `prep <KEY> for PR #<N> (base origin/<BASE_REF>). Human QA only — agent-qa will not run.`
  plus these known issues:
  - (only when the pre-flight found rows) `up.sh` will die on its leak check after applying the
    schema because literal clarity/zod DBs already exist — re-run `up.sh --also-testing` once;
    it skips the apply.
  - A duplicate-key error on the first seeder insert means a `platform` sequence is behind
    (`LocalDataSeeder` inserts explicit ids) — `setval` it to `max(id)` in the branch DB only.

On a first run it bootstraps (branch DB, migrate, seed, `herd link` + `secure`), enables dev login,
stands up CH, patches `tiger.state`, seeds, writes both plans + the ledger. On a switch it reuses
what's standing and re-scopes/reseeds for the new tip. Surface its caveats. Never run agent-qa.

### 5b. Harden the environment

qa-prep leaves three known gaps. Fix them here, in the worktree only.

**Boost drift.** Setup regenerates the Laravel Boost docs. The QA worktree is read-only, so restore
them (explicit list — zsh doesn't word-split a `$VAR`):

```bash
for f in CLAUDE.md AGENTS.md .junie/guidelines.md .cursor/rules/laravel-boost.mdc boost.json; do
  git ls-files --error-unmatch "$f" >/dev/null 2>&1 && ! git diff --quiet HEAD -- "$f" && git checkout HEAD -- "$f"
done
git status --porcelain
```

Anything still listed isn't Boost — report it, don't revert it.

**ClickHouse password.** `up.sh` can leave `CLICKHOUSE_PASSWORD` empty while the container requires
one; `ClickhouseQuery` swallows the auth error, so the page just looks empty. Probe from the host:

```bash
envv() { grep -m1 -E "^$1=" .env | cut -d= -f2- | tr -d '"'; }
curl -s -o /dev/null -w '%{http_code}\n' -u "$(envv CLICKHOUSE_USERNAME):$(envv CLICKHOUSE_PASSWORD)" \
  "http://127.0.0.1:$(envv CLICKHOUSE_PORT)/?query=SELECT%201"
```

Not `200` → read the password from the container and write it to both env files without printing it:

```bash
CONTAINER=$(jq -r '.clickhouse.container // "clarity-qa-clickhouse"' .context/qa-prep-ledger.json)
export CH_PW=$(docker inspect "$CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' | sed -n 's/^CLICKHOUSE_PASSWORD=//p')
for file in .env .env.testing; do
  for key in CLICKHOUSE_PASSWORD CLICKHOUSE_WRITE_PASSWORD; do
    KEY=$key perl -pi -e 's/^\Q$ENV{KEY}\E=.*/$ENV{KEY}=$ENV{CH_PW}/' "$file"
  done
done
php artisan config:clear
```

Re-probe; still not `200` → stop and report (never point the worktree at Cloud as a workaround).

**Duplicate CH rows.** A re-run CH seed leaves duplicate parts, and the CH `Hit` model doesn't query
with FINAL, so every hit renders twice until a merge. Merge the seeded tables now:

```bash
SEED=$(jq -r '.artifacts.ch_seed_sql // ".context/qa-ch-seed.sql"' .context/qa-prep-ledger.json)
for t in $(grep -oiE 'INSERT INTO [`"]?[A-Za-z0-9_.]+' "$SEED" | awk '{print $3}' | tr -d '`"' | sed 's/.*\.//' | sort -u); do
  for db in $(jq -r '.clickhouse.databases[]' .context/qa-prep-ledger.json); do
    docker exec "$CONTAINER" clickhouse-client -q "EXISTS TABLE $db.$t" | grep -qx 1 \
      && docker exec "$CONTAINER" clickhouse-client -q "OPTIMIZE TABLE $db.$t FINAL"
  done
done
```

Repeat this block after any CH re-seed during QA.

### 6. Refresh pass

Edit `.context/qa-plan-human.md` in place — never touch `head_sha` / `wip_sha`. Inputs, when on
the branch: `docs/investigations/<KEY>/{prep-context.json,plan.md,build-record.md,state.json}`, the
PR body, and the Jira ACs qa-prep fetched.

1. **Tip drift.** If the tip the artifacts describe (`state.json`, the build record's product /
   reviewed sha) isn't `HEAD_OID`, add an Environment callout with
   `git log --oneline <recorded>..HEAD`.
2. **AC coverage.** Every Jira AC, committed `acceptance_criteria`, and product lock / hard stop
   (`plan.md`, `prep-context.json`) maps to an `hp-n` or `ec-n`; append the missing ones in the
   plan's format with an `AC-n` reference.
3. **Build-record extras.** Deferred items, N/A behavior, and known limits become `ec-n` items.
4. **Seed gaps.** An added item whose data isn't seeded → mark it `needs data`, tell the user, and
   offer to extend `.context/qa-seed.tinker.php`. Never reseed silently.

No `docs/investigations/<KEY>/` → skip 1 and 3; still run 2 against Jira.

### 7. QA guide (human-qa)

Invoke **`insightlpr:human-qa`** with `human QA this <KEY>` (no key when ticketless). It verifies
the env, renders `.context/qa-human-guide.html`, and opens it.

### 8. Report

- Site URL (`.env` `APP_URL`) and the persona `/__qa/login/{id}` links.
- Postgres dev/test DBs and ClickHouse DBs (from the ledger).
- Guide path, plus any drift callout / `needs data` items.
- Anything Step 5b fixed or couldn't fix, and the leftover literal CH databases if found.
- Next: *when you're done, click **Copy results as Markdown** in the guide, then run
  `/pr-qa complete <N>`.*

## Complete — `/pr-qa complete <N>`

### 1. Locate

Resolve `MAIN` and `WT` as in Setup step 2. No `$WT` → say so and stop. Read `$WT/.context/pr-qa.json`
into `KEY`, `BR`, `KIND` (`branch_kind`), `PR_URL`, `HEAD_OID`. Call **`EnterWorktree` with `path: $WT`**.

### 2. Get the results

```bash
pbpaste
```

Valid when it starts with `# Human QA results —` (the guide's export header) and that line or the
`**Progress:**` line names `KEY` or `BR`. Otherwise ask Dalton to click **Copy results as Markdown**
and paste it here — or give a freeform verdict.

### 3. Draft the comment

Write `.context/pr-qa-comment.md`:

```markdown
## Human QA — <KEY> · PR #<N> @ `<short head_oid>`

**Verdict:** ✅ PASS | ⚠️ NEEDS ATTENTION
<for NEEDS ATTENTION: the unchecked steps and every step with a note, one line each>

**Environment:** local Herd site, branch Postgres, local Docker ClickHouse, seeded QA personas.
<tip-drift callout from the refresh pass, if any>

<details><summary>Full checklist</summary>

<the pasted export, verbatim>

</details>
```

Verdict: PASS only when every step is `- [x]` and there are no `note:` lines; otherwise NEEDS
ATTENTION. Dalton can override it. Nothing from `.env`, no credentials, no customer data.

### 4. Approval gate

Show the draft and wait for an explicit yes — posting is outward-facing. Requested edits → revise
and show again.

### 5. Post

```bash
gh pr comment "$N" --repo insight-lpr/platform --body-file .context/pr-qa-comment.md
```

Print the comment URL. If Dalton declines to post, ask whether to still tear down; stop on no.

### 6. Tear down

1. Invoke **`insightlpr:qa-complete`** with `QA_TEARDOWN_ONLY=1`. It drops only the ledger's
   guard-matched resources — CH DBs (`down.sh --also-testing`), the branch Postgres dev/test DBs,
   the Herd link — and skips its own Jira/push/PR steps. If it refuses (no ledger,
   `teardown_allowed: false`), show why and stop here — never hand-drop databases.
2. `ExitWorktree` with `action: keep`.
3. Remove the worktree and its branch:

   ```bash
   git -C "$MAIN" worktree remove --force "$WT"
   if [ "$KIND" = mirror ]; then git -C "$MAIN" branch -D "$BR"; else git -C "$MAIN" branch -d "$BR"; fi
   ```

   A real branch tracks `origin/<head>` with no local commits, so the safe `-d` succeeds; if it
   refuses, report it and leave the branch.

### 7. Report

The PR comment URL and what was removed (DBs, CH DBs, Herd site, worktree, branch).

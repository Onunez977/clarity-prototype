---
name: release
description: >
  Ship a Clarity release to PRODUCTION end-to-end. The USER provides the release number (e.g.
  "release v3.10.0"); the skill NEVER computes, guesses, or semver-bumps it. It (1) builds a
  Jira-enriched ticket inventory from the last released tag to HEAD, (2) finalizes the customer
  changelog + version — writing composer.json + a new config/changelog.php entry and filling its
  change lines (the /document-changes logic, absorbed) — then COMMITS + PUSHES that to main, (3)
  opens a main→deploy pull request titled "RC vX.Y.Z", (4) WAITS for the "Pint & Tests" CI check to
  pass, diagnosing and fixing+retrying mechanical failures (never merging on red), (5) merges the PR
  into the deploy branch and then WAITS for the separate "Deployment" workflow to pass on deploy and
  auto-commit its signed .deploy/*.asc files (Pint & Tests is pull_request-only, so this is a second,
  independent gate), (6) brings up the Insight WireGuard VPN once (held for the window), SSHes the prod box, and runs
  `php artisan app:deploy --no-confirm`, publishes a GitHub Release for that version (tag + generated
  notes — not a bare annotated tag), then merges deploy back into main and pushes so the branches
  stop drifting, (7) MONITORS prod logs + Datadog for 2 hours on a front-loaded cadence (every 5 min for
  the first 20 min, then every 20 min), pulling over the held VPN (no per-cycle up/down) and alerting
  IN-THREAD on any new errors attributable to the release — and on each new error it DIAGNOSES and
  TRIAGES it (7-day pre-deploy Datadog control window, severity S1-S4, mapped back to the shipped
  files) and, once the user confirms that incident, creates or re-opens a DEV Jira bug with the full
  evidence and SPAWNS a background Claude Code session to work it, (8) writes back to Jira for the
  release itself — transitions the shipped tickets to Done with their resolution, sets the
  "Clarity vX.Y.Z" fix version where it is empty, and posts a follow-up comment on every ticket whose
  effect is DEFERRED (a queued job, a scheduled command, a materialized view, or a hand-applied
  migration) so nobody reads Done as "live" — and (9) drafts a release email for the PM (Yolanda) with
  the Jira links, what changed, why, the release date and version. Scope is CUTOVER ONLY — it assumes local QA already ran via the
  qa-prep → agent-qa → human-qa pipeline and does NOT provision QA, seed, or run agent-qa. It absorbs
  the version/changelog/inventory logic of the release-prep skill and the /document-changes command;
  both of those stay in place for their standalone / pre-release uses. TRIGGER when the user says
  "release", "/release", "ship", or "deploy release" FOLLOWED BY a version number — e.g.
  "release v3.10.0", "/release 3.10.0", "ship v3.10.0". The version number is what marks a real
  trigger; if "release" appears with NO version, do NOT auto-run — ask which version and stop. Never
  derive or bump the number. This skill PUSHES to main, MERGES a PR into deploy, and DEPLOYS to
  production and WRITES to Jira, so it CONFIRMS with the user at THREE prod gates — before starting
  (Phase 0), before merging the PR into deploy (Phase 5), and before the production app:deploy
  (Phase 7) — plus a fourth confirm before the bulk Jira write-back (Phase 8b) and a per-incident
  confirm before each triage ticket + spawned session during the watch. It hard-stops on a CI failure
  (either workflow), a moved HEAD, or a deploy error. During the 2-hour watch the user can say "stop monitoring" to
  end it early and get the release report immediately. A `--dry-run` argument runs a full rehearsal that
  performs NO prod-affecting action (writes the changelog locally then reverts, opens a throwaway CI PR
  and closes it, substitutes a read-only command for app:deploy, runs the monitor on a compressed clock).
  Do NOT trigger on "release prep"/"/release-prep" (that's the QA-scoped release-prep skill) or "document
  changes" (that's the changelog command alone).
---

# Release

Turns "we're shipping vX.Y.Z" into the whole production cutover: **changelog → main→deploy PR → CI
gate → merge → VPN/SSH deploy → 2-hour prod watch → PM email.** It is the production sibling of
`release-prep`: where `release-prep` preps a release for QA and stops at pushing the `vX.Y.Z` tag,
`release` performs the actual cutover and watches prod afterward.

## Mental model

- **This deploys to PRODUCTION, behind three checkpoints.** The prod-affecting actions each get an
  explicit gate: **(1)** Phase 0 — a go-ahead to *start* (push the version/changelog to `main`, open
  the RC PR, run CI); **(2)** Phase 5 — a confirm right before **merging the RC PR into `deploy`**;
  **(3)** Phase 7 — a confirm right before the **SSH `app:deploy`** on the prod box (the point of no
  return). Between gates it runs autonomously (inventory, changelog, CI-watch + auto-fix), but it
  **stops and waits** at each of the three, and **hard-stops** on red CI, a moved HEAD, or a deploy
  error. Never merge or deploy on red CI. Rehearse the whole flow first with **`--dry-run`** — it makes
  no prod-affecting change (see [`dry-run.md`](dry-run.md)).
- **The user owns the release number.** It is a required argument (`release v3.10.0`). The skill
  **propagates** it — bare number → `composer.json` `"version"`, `vX.Y.Z` → the new
  `config/changelog.php` entry — and never computes, guesses, or semver-bumps it. If it's missing,
  **ask and stop.**
- **Cutover only — no QA machinery.** Local QA is assumed done (`qa-prep → agent-qa → human-qa`).
  This skill does NOT provision an env, seed, write QA plans, or chain into agent-qa. It absorbs only
  the *documentation* half of `release-prep`: the ticket inventory (see
  [`changelog-and-email.md`](changelog-and-email.md)) and the changelog fill (the `/document-changes`
  rules, also in that satellite).
- **The branch model is `main → deploy`.** Production is checked out on the **`deploy`** branch;
  `app/Console/Commands/Deploy.php` runs a bare `git pull` that fast-forwards it. So a release is:
  land version+changelog on `main` → open a PR **base `deploy`, head `main`** → CI passes → merge →
  SSH prod → `app:deploy`. The Kamal workflow (`kamal-deploy.yml` / `config/deploy.yml` / `.kamal/`)
  targets the **QA** host, not prod — **never touch it.**
- **Two CI gates, on different triggers.** `lint_check_and_test.yml` (**`Pint & Tests`**) is
  `on: pull_request` **only** — it gates the RC PR and nothing else. `deploy.yml` (**`Deployment`**) is
  `on: push` to `main` and `deploy`, so it fires *after* the merge, and it is the one that GPG-signs and
  **auto-commits** `.deploy/authorization_<branch>.txt.asc`. Waiting on the first does **not** cover the
  second, so there are two waits: Phase 4 (PR) and Phase 5b (post-merge). Prod's `app:deploy` is a bare
  `git pull`, so 5b must also see the auto-commit land or prod ships stale signed files. That same
  auto-commit is why `main` and `deploy` drift a commit per release — which Phase 7b then reconciles.
  Three traps on the PR gate: the release commit must NOT contain `[skip ci]` (GitHub-native suppression →
  no check fires), the PR must be **non-draft**, and you poll the release commit's **head SHA** —
  `gh pr checks` gets misleading once `main` advances. See [`deploy-cycle.md`](deploy-cycle.md).
- **Jira gets written, on a conservative policy.** The release closes its own tickets: `Done` +
  resolution, and the `Clarity vX.Y.Z` fix version — but **only into an empty field.** A ticket already
  carrying a different version is left alone and flagged, because those are set at planning time and are
  the team's data. The version must **pre-exist**: no MCP tool creates a Jira version, and the project
  carries only `.0` minors, so a patch release usually finds none — note it and skip, never substitute.
  Tickets whose effect is *deferred* (a queued job, a scheduled command, a materialized view, a migration
  `app:deploy` cannot apply) get a follow-up comment, because `Done` otherwise reads as "live". See
  [`jira-writeback.md`](jira-writeback.md).
- **The monitor files work, it doesn't just report.** A new error signature is diagnosed, not merely
  announced: the decisive evidence is a **7-day pre-`T0` Datadog control window** (an empty one is what
  actually proves the release caused it — the 24 h baseline misses anything rarer than daily), then a
  severity S1–S4 and a map back to the shipped files. On the user's per-incident yes it creates or
  re-opens a DEV bug and spawns a background session on it. Each signature is ticketed **at most once**,
  tracked in the state file, and monitoring never pauses for any of it. See
  [`monitor-loop.md`](monitor-loop.md) §Triage.
- **The VPN + log pull are borrowed; prod SSH is wrapped.** `release` reuses the `terminal-log-triage`
  skill's `vpn.sh` (idempotent connect/disconnect) and `pull_logs.sh` (read-only, path-allowlisted
  log pull, always run with `--no-vpn` over the held tunnel). Everything else against prod goes through this
  skill's own `scripts/prod-ssh.sh` — a **verbs-only** wrapper (`deploy` | `verify`) that never accepts
  a raw remote command; there is no hand-rolled ssh anywhere in the flow. The prod box has no
  server-side restriction, so the wrapper *is* the boundary. See
  [`deploy-cycle.md`](deploy-cycle.md) §Prod SSH contract + [`monitor-loop.md`](monitor-loop.md).
- **Errors are attributed by signature, not clock.** The 2-hour watch seeds a "seen" set from a
  **pre-deploy** baseline (so a fast-appearing release error can't be mistaken for pre-existing) and
  alerts only on error **signatures** that are new (or newly spiking) after `T0`. This is
  timezone-robust — the prod log clock need not match the local clock. See
  [`monitor-loop.md`](monitor-loop.md).
- **Email is draft-only.** No real send path exists here (the mailer is a Mailtrap sandbox; no
  Gmail/Outlook connector is authorized). The skill assembles a copy-paste-ready draft for Yolanda.
- **Satellite files** (read at the phase that needs them):
  [`changelog-and-email.md`](changelog-and-email.md) — ticket inventory + Jira enrichment, the
  changelog write/fill/coverage rules, and the PM email template;
  [`deploy-cycle.md`](deploy-cycle.md) — the PR → CI-watch (+ fix loop) → merge → **post-merge
  `Deployment` gate** → VPN/SSH/`app:deploy` → gated GitHub Release (tag + release notes) →
  **`deploy`→`main` sync** runbook with exact
  commands; [`monitor-loop.md`](monitor-loop.md) — the 2-hour cadence, Datadog queries, error attribution,
  alert format, **§Triage** (diagnose → severity → Gate T), and the stop condition;
  [`jira-writeback.md`](jira-writeback.md) — the two Jira-writing paths: the release closeout (Done + fix
  version + deferred-effect comments) and the triage ticket (create/re-open + link + spawn);
  [`dry-run.md`](dry-run.md) — the `--dry-run` rehearsal (safe substitutes + guaranteed cleanup) across
  the whole flow, Phases 0–10.

## Trigger

- **Ship a release** — triggered by **"release" / "/release" / "ship" / "deploy release"** + a
  version: `release v3.10.0`, `/release 3.10.0`, `ship v3.10.0`. Run the [Run flow](#run-flow).
- **The version number is the trigger signal.** "release" with **no** number is NOT a run — ask
  "which version am I releasing? e.g. v3.10.0" and stop. Never guess or bump.
- **Stop the monitor** — during the 2-hour watch, **"stop monitoring"** (/ "stop the monitor" / "end
  monitoring") ends the loop early: halt, one final check, take the held VPN down, drain pending work, and emit
  the **Release Report** (see [`monitor-loop.md`](monitor-loop.md) §Stop). Keys off the active
  `.context/release-monitor.json` — so it works even after a context reset.
- **Mid-window gate replies** — a bare "yes" / "ticket only" / "neither" during the watch answers a
  **Gate T** prompt: map it onto the `$STATE.triage[]` entries with `gate:"pending"` (they are recorded at
  post time precisely so this works after a context reset); if more than one is pending, ask which. A
  "yes" on the Gate-4 table likewise keys off `pending.jira_closeout` being `"todo"`/`"deferred"`.
- Not this skill: **"release prep" / "/release-prep"** (the QA-scoped `release-prep` skill) and
  **"document changes"** (the changelog command alone).

## Run flow

### Phase 0: Inputs, preflight & the first go-ahead (Gate 1)

Capture the **release number** (required; accept `v3.10.0` or bare `3.10.0`) and an optional release
ticket key:

```bash
RAW_VERSION=$(printf '%s' "<the user's message>" | grep -oiE 'v?[0-9]+\.[0-9]+\.[0-9]+' | head -1)
REL_VERSION="v${RAW_VERSION#v}"        # vX.Y.Z — the changelog key
BARE_VERSION="${REL_VERSION#v}"        # X.Y.Z  — the composer.json value
REL_TICKET=$(printf '%s' "<the user's message>" | grep -oE '[A-Z][A-Z0-9]+-[0-9]+' | head -1)
DRY_RUN=0; printf '%s' "<the user's message>" | grep -qiE '(^| )--dry-run( |=|$)' && DRY_RUN=1
```

If `RAW_VERSION` is empty, **ask which version and stop** — never compute, guess, or bump it.

**If `DRY_RUN=1`**, follow [`dry-run.md`](dry-run.md): announce up front that this rehearsal makes NO
prod-affecting change (no push to `main`, no merge, no `app:deploy`, no GitHub Release, **no Jira write,
no session spawn**), then run its read-only / throwaway substitutes and guaranteed cleanup. Phases 0, 1,
and 10 are the same in both modes (Phase 6 differs only in stamping `"dry_run":1` into the state file);
Gates 2/3/4 and Gate T become informational `WOULD-confirm` prints (Gate 1 still takes a yes — a dry run still opens a
real throwaway PR and cycles the VPN).

**Preflight (fail loud on any):**

```bash
TARGET="$(pwd)"                                   # run from main (or a worktree off it)
BRANCH=$(git -C "$TARGET" rev-parse --abbrev-ref HEAD)
gh auth status                                    # must be logged in (repo+workflow scopes)
[ -z "$(git -C "$TARGET" status --porcelain)" ] || { echo "🚨 working tree not clean — commit or stash first"; exit 1; }
git -C "$TARGET" fetch origin main deploy --tags --quiet
git -C "$TARGET" pull --ff-only                   # refuse if not fast-forwardable
HEAD_SHA=$(git -C "$TARGET" rev-parse HEAD)
[ "$HEAD_SHA" = "$(git -C "$TARGET" rev-parse origin/main)" ] || { echo "🚨 HEAD != origin/main — stop"; exit 1; }
```

- Must be on `main` (or a worktree whose HEAD == `origin/main`), **clean working tree**, `HEAD ==
  origin/main`. If behind → tell the user to pull; if ahead → the commits aren't on `origin/main`
  yet. Stop on either.
- Confirm the borrowed tooling exists: `~/Herd/clarity/.claude/skills/terminal-log-triage/scripts/{vpn.sh,pull_logs.sh}`
  and its `config.local.sh` (the VPN tunnel name + SSH target live there). If missing, stop and tell
  the user to set it up.
- Confirm this skill's own prod wrapper exists and is executable:
  `[ -x "$TARGET/.claude/skills/release/scripts/prod-ssh.sh" ]` — every remote command in Phases 6–9
  routes through it or `pull_logs.sh`; if it's missing, stop.
- Sanity-check `$REL_VERSION` > the last tag (`git describe --tags --abbrev=0`); warn + confirm if
  not, but never change the number yourself.

**Then present the cutover and take the first go-ahead.** Summarize what will happen — *version,
ticket count (from a quick `DEV-####` scan of the range), and the sequence: push version+changelog to
main → open RC PR into deploy → wait for `Pint & Tests` → merge → wait for `Deployment` on deploy →
VPN/SSH `app:deploy` on prod → GitHub Release (tag + notes) → merge deploy back into main and push →
monitor 2h — with the Jira
write-back of the shipped tickets (its own confirm) right after the first clean post-deploy check, and
triage/ticketing of any new error on your per-incident say-so → draft the PM email* —
and get an explicit **yes**. This go-ahead authorizes only the *start*: pushing the version/changelog to
`main` (Phase 2), opening the PR (Phase 3), running/auto-fixing CI (Phase 4) — **and the fast-forward
`deploy → main` sync push at Phase 7b**, which is announced here so it needs no separate confirm.
**Everything else takes its own confirm at the moment it happens**: the merge (Gate 2, Phase 5), the prod
deploy (Gate 3, Phase 7), a *non*-fast-forward sync (Phase 7b), the bulk Jira write-back (Gate 4, Phase
8b), and each triage ticket + spawned session during the watch (Gate T, Phase 9). Don't treat this yes as
covering any of them. Do not proceed without it.

### Phase 1: Release inventory

Read [`changelog-and-email.md`](changelog-and-email.md) §Inventory and follow it: range = `<last
tag>..HEAD`, apply `rel_filter`, group commits by first `DEV-####` into `{key, commits[], files[]}`
(+ an `Unticketed` bucket), and enrich each key via the Atlassian MCP (title + "what/why"). This
inventory feeds both the changelog coverage check (Phase 2) and the PM email (Phase 10).

### Phase 2: Version + changelog → commit + push to main

Read [`changelog-and-email.md`](changelog-and-email.md) §Changelog. Write `composer.json` `"version"`
= `$BARE_VERSION`; prepend the `config/changelog.php` entry (`'$REL_VERSION' => ['date' => '<today,
"Month Nth, YYYY">', 'changes' => []]`) and fill `changes` from the Phase-1 inventory using the
absorbed `/document-changes` style + gated-feature-exclusion rules. Run the **coverage cross-check**
(every inventory ticket appears or is deliberately excluded; flag MISSING).

Then commit + push — **the commit message must not contain any `[skip ci]` token** (or CI won't fire):

```bash
git -C "$TARGET" add composer.json config/changelog.php
# Only commit if something is actually staged (warm re-runs may already be committed).
if ! git -C "$TARGET" diff --cached --quiet; then
  git -C "$TARGET" commit -m "RC $REL_VERSION"    # message MUST NOT contain [skip ci]
fi
git -C "$TARGET" push origin HEAD:main \
  || { echo "🚨 push to main rejected — main moved under us (someone else pushed) or it's protected. Stop, pull --ff-only, and re-run; do NOT continue to the PR."; exit 1; }
NEW_HEAD_SHA=$(git -C "$TARGET" rev-parse HEAD)
```

*(Built assets are assumed current on `main` — `app:deploy` runs no `npm run build`.)*

### Phase 3: Open the RC PR (main → deploy)

Read [`deploy-cycle.md`](deploy-cycle.md) §PR. Create a **non-draft** PR with base `deploy`, head
`main`, title **`RC $REL_VERSION`**, body summarizing the shipped tickets. Capture `PR_NUM` and
`HEAD_SHA=$NEW_HEAD_SHA`.

### Phase 4: Watch CI (`Pint & Tests`)

Read [`deploy-cycle.md`](deploy-cycle.md) §CI. Poll the `Pint & Tests` run for `HEAD_SHA`.
- **Green →** proceed.
- **No run appears** within ~90s → defensive stop (head carried `[skip ci]`, or the PR is a draft) —
  report and fix the cause, don't loop forever.
- **Red →** fetch `gh run view <id> --log-failed`, classify (pint → `composer format`; test → a
  targeted fix), commit to `main` (no `[skip ci]`), re-push (re-triggers CI), re-capture `HEAD_SHA`,
  re-poll. **Bounded to ≤2 attempts.** Anything non-mechanical, or still red → **STOP, surface the
  logs, do NOT merge.**

### Phase 5: Merge into deploy

Read [`deploy-cycle.md`](deploy-cycle.md) §Merge. First **re-verify what is about to merge**: Phase 2's push
fired `Deployment` on `main`, whose auto-commit advanced the PR head past the `$HEAD_SHA` Phase 4 gated —
benign, but anything *else* on `main` means someone else pushed, so assert every added commit is a
`GHA deploy` commit and **STOP** otherwise. Then **Gate 2 — confirm before merging.** With CI green, show
what will happen (merge RC #NN `main → deploy`) and get an explicit **yes**. Only then
`gh pr merge $PR_NUM --merge` (regular merge commit) and capture `$MERGE_SHA` — the merge commit on
`origin/deploy`, which is what Phase 5b polls.

### Phase 5b: Post-merge CI gate (`Deployment` on `deploy`)

Read [`deploy-cycle.md`](deploy-cycle.md) §Post-merge CI. The merge pushes to `deploy`, which fires the
**`Deployment`** workflow — pint + tests again, then a GPG-clearsign step that **auto-commits**
`.deploy/authorization_deploy.txt.asc` + `commands_deploy.txt.asc` back onto `deploy`. `Pint & Tests` does
NOT run here (`on: pull_request` only), so this is a second, independent gate.

Poll the run for `$MERGE_SHA` **on branch `deploy`** as a **background** call — measured durations are
12–13 min, so the deadline is **30 min**, not Phase 4's 20 (this workflow runs the tests unsharded). Green
is not sufficient: also confirm the `GHA deploy - Run ID: <id>` commit is on `origin/deploy` and capture
`$DEPLOY_TIP`, because prod's `app:deploy` is a bare `git pull` and would otherwise ship stale signed files.

- **Green + auto-commit landed →** proceed to Phase 6 carrying `$DEPLOY_TIP`.
- **Red / no run / still running / green-but-no-auto-commit → HARD STOP, do NOT deploy.** Unlike Phase 4
  there is **no fix loop**: the merge is already on `deploy`, and Phase 4's loop pushes to `main`, which
  cannot fix it. Recovery is a follow-up PR or a revert — say so rather than looping.
- Warm re-run: if `origin/deploy`'s head subject already starts `GHA deploy - Run ID:`, 5b is satisfied —
  short-circuit, don't poll. Never wait for a run *after* the auto-commit; it is pushed with the default
  `GITHUB_TOKEN` and triggers nothing.

No new gate — 5b is a read-only wait covered by Gate 2, and Gate 3 still guards the deploy.

### Phase 6: Deploy preflight + pre-deploy baseline

Read [`deploy-cycle.md`](deploy-cycle.md) §Preflight. Probe passwordless `sudo` for `wg-quick`
(`sudo -n -l wg-quick`) — a non-interactive shell can't answer a password prompt, so if it would prompt,
**stop and report**. Otherwise bring the VPN up **once** (`vpn.sh connect`, its own call), confirm the
Mac mini still responds, and record `held_tunnel:true`: the release operator holds this one tunnel until
after the monitor's final pull.

Then, **before the deploy** (so no release error can contaminate it), seed the monitoring baseline per
[`monitor-loop.md`](monitor-loop.md) §Baseline: one read-only `--no-vpn` prod-log pull + a pre-now Datadog query (window coordinator),
whose error signatures become the `SEEN` set of "errors that already existed." Persist them to the
monitor state file so the loop survives a mid-window context reset.

### Phase 7: Deploy to prod

Read [`deploy-cycle.md`](deploy-cycle.md) §Deploy. **Gate 3 — confirm before deploying (point of no
return).** State plainly: "about to run `app:deploy --no-confirm` on production
(`ubuntu@10.100.3.142:~/code/platform`) over the held tunnel" and get an explicit **yes**. Only then capture the deploy
timestamp **`T0`** and run — in its own call over the held tunnel (no connect, disconnect, or trap) and
**with a long Bash timeout (600000 ms)** because `composer install` can exceed the default — the single
write against prod: `bash "$RSS" deploy` (the verbs-only wrapper `scripts/prod-ssh.sh`; it runs
`php artisan app:deploy --no-confirm` on `ubuntu@10.100.3.142:~/code/platform`). Capture + parse the output (fail loud on unreachable host / git-conflict / composer
error; **abort before monitoring**). If the call is ever hard-killed mid-run, **reconcile with the
read-only `verify`** (§Deploy) before anything else. On success, offer the **gated + confirmed** GitHub Release for
`$REL_VERSION` (tag + generated notes).

### Phase 7b: Sync `deploy` → `main`

Read [`deploy-cycle.md`](deploy-cycle.md) §Sync. `main` and `deploy` each carry their own `GHA deploy`
auto-commit, so they drift a commit per release; merge `origin/deploy` back into `main` and push. Today
`main` is strictly behind (`0 2`), so this is a clean **fast-forward**.

**Order matters — this runs after the gated GitHub Release**, not before: pushing `main` re-triggers
`Deployment` on `main`, whose auto-commit moves `origin/main`. The Release step allows only `GHA deploy`
commits between `$HEAD_SHA` and `origin/main`; a sync-first merge is not one of those, so the guard fails
and the Release is silently skipped. **Publish the Release first, sync second.**

Fast-forward with `--ff-only`; if `main` moved, probe with `git merge-tree --write-tree` **before** starting
a merge, and take a **fresh confirm** for that non-fast-forward push. **Never hard-stop here** — prod is
already live and the 2-hour watch matters more than a bookkeeping merge: on conflict, dirty tree, or a
rejected push, record `pending.sync_main` in the state file, print the commands, and continue to Phase 8.
Expect a benign `Deployment` run on `main` afterwards — **never wait on it** (and note the next release's
Phase 0 will need a `git pull` first).

### Phase 8: First post-deploy check

Read [`monitor-loop.md`](monitor-loop.md) §Loop. Immediately after the deploy, run the first tick —
Datadog (window coordinator, no VPN) + a `pull_logs.sh --no-vpn` prod-log pull from `T0` — and compare against the
pre-deploy `SEEN` baseline. Alert in-thread on any new signature; otherwise report "post-deploy: clean
so far." This is tick 0; the cadence timer runs from `T0`.

### Phase 8b: Jira release write-back (Gate 4)

Read [`jira-writeback.md`](jira-writeback.md) §Release write-back. Runs **after tick 0** and before the T+5
tick: tick 0 is time-critical and this is not, and the operator is demonstrably still present two minutes
after authorizing a deploy — which matters, because this needs a human yes. `pending.jira_closeout` is a **tri-state**
(`"todo"` at the baseline write → `"deferred"` → `"done"`) — a boolean cannot tell "never ran" from
"done", and a reset in the tick-0 → T+5 gap would silently lose the closeout. **If tick 0 already flagged
a 🔴, or Gate 4 goes unanswered by the time T+5 comes due, set `"deferred"`, keep the cadence, and let
§Stop run it** once the window has resolved — never stall the watch on an unanswered prompt, never
proceed without the yes.

Resolve `Clarity $REL_VERSION` from `fixVersions.allowedValues` once (the response is large and usually
spills to a file — read it with `jq`). Not found → **skip fix versions for the whole release** and note it
with the nearest existing minor line; no MCP tool creates a Jira version. Then **Gate 4** — show the per-
ticket table (status → Done, fix-version action, comment y/n) and take one yes for the batch.

Per ticket: read first (already `Done` → skip the transition; `Done` is globally available and a naive
re-run churns the resolution date) → fill the fix version **only if empty** → transition `Done` **bare**
(every DEV transition is `hasScreen:false`, so bundled fields are rejected) → set `customfield_10302`
("Resolution\*") if the transition left it empty. Tickets whose `files[]` mark them **deferred-effect** —
`app/Jobs/`, `app/Console/Commands/` + `routes/console.php`, `database/migrations/`, ClickHouse views,
observers/listeners, notifications/mail — also get a follow-up comment naming the mechanism and its real
cadence. A migration is an **outstanding manual step**: `app:deploy` runs no migrations and prod
`artisan migrate` is blocked, so it goes in both the comment and the Release Report.

### Phase 9: Two-hour monitor loop

Read [`monitor-loop.md`](monitor-loop.md) §Loop. Cadence: **every 5 min for the first 20 min**
(T+5/10/15/20), then **every 20 min** to T+120 (T+40/60/80/100/120). Each tick: Datadog check (window
coordinator, no VPN) + a `pull_logs.sh --no-vpn` pull over the held tunnel, scan for errors, and **alert in-thread as plain text
only on new signatures** (message, count, first-seen, source) — otherwise a quiet "tick N clean".
Ticks run on the window coordinator's slot pings; **stop at T+120 — or immediately when the user says "stop monitoring"** — then run
§Stop: halt the loop, a final check, take the held VPN down, drain anything left in `pending`, the **Release Report**
(which feeds Phase 10), and only then delete the state file.

**On any new signature, after the alert is posted**, run [`monitor-loop.md`](monitor-loop.md) §Triage:
gather evidence read-only from the tick's **already-pulled** log (never re-pull — that's another Mac mini call),
have the window coordinator query Datadog for the post-`T0` rate **and the 7-day pre-`T0` control window** (an empty control is what
actually proves attribution; the 24 h baseline misses anything rarer than daily), then map the failing app
frame back to the Phase-1 inventory and read that code locally. Assign **S1–S4** → `Highest/High/Medium/Low`
and a verdict of `attributable | plausible | unrelated`.

**Gate T (per incident, repeatable).** S1/S2 always warrant a ticket, S3 only if it recurs across ≥2 ticks
or spans multiple orgs, S4 never, and `unrelated` never gets filed from this skill. Present the diagnosis
and take one yes covering **both** the Jira write (create a DEV Bug, or re-open/comment on a match found via
JQL) **and** the spawn of a background session on it — `yes` / `ticket only` / `neither`. A yes on one
incident never covers another, and silence is not consent: record `pending`, keep monitoring, re-surface
once. **For an S1, lead with a rollback/hotfix recommendation, not with a ticket.** Each signature is
ticketed **at most once** (deduped via `$STATE.triage[]`, so a nine-tick window cannot file nine
duplicates), and **the watch never pauses** — the spawn is detached, in its own worktree.

### Phase 10: PM release email

Read [`changelog-and-email.md`](changelog-and-email.md) §Email. Assemble a **draft** for Yolanda —
version + release date, per-ticket "what changed / why" + Jira links, and the one-line outcome from the
§Stop **Release Report** — and present it in-thread to copy-paste. Resolve Yolanda's address (user id 35)
or ask; note there is no automated send path today. Carry the report's `Outstanding` items (a
hand-applied migration, deferred-effect work awaiting its next run, a deferred `deploy → main` sync) and any
tickets the watch filed, so she isn't surprised by either. If the report has open 🔴 errors, recommend
holding the email until they're triaged.

## Things to be careful about

- **Three prod gates, plus Gate 4 and Gate T.** Confirm at Phase 0 (start), Phase 5 (merge), and Phase 7
  (prod deploy) — the prod-affecting three. Then **Gate 4** before the bulk Jira write-back (Phase 8b) and
  **Gate T** per triage incident (Phase 9), plus a conditional confirm on a non-fast-forward sync (7b).
  Between gates, don't re-prompt for routine steps; but DO hard-stop on: red CI in **either** workflow
  (never merge, never deploy), HEAD moved off `origin/main`, a rejected push, a failed/blocked merge, a VPN
  that won't come up, or a deploy error. An existing Release or tag is idempotent success (print the URL) —
  never `-f`, never move the tag.
- **`[skip ci]` and drafts kill the gate.** The release/fix commits must be clean of `[skip ci]`, and
  the PR must be non-draft, or there is no `Pint & Tests` run to wait on. `[skip ci]` on the merge commit
  likewise suppresses the Phase-5b `Deployment` run.
- **Poll CI by head SHA, not by later `gh pr checks`** — and for Phase 5b, filter on `headBranch == deploy`
  too. After 7b, the *same* sha gets a second `Deployment` run on `main`; sha alone matches the wrong run.
- **Phase 5b has no fix loop, and 7b never hard-stops.** A red `Deployment` on `deploy` cannot be fixed by
  pushing to `main` — the merge already landed, so recovery is a follow-up PR or a revert. Conversely, a
  failed `deploy → main` sync is bookkeeping: prod is live, so record it and keep monitoring.
- **Publish the GitHub Release before syncing.** 7b's push to `main` moves `origin/main` (a merge that is
  not a `GHA deploy` commit, then another auto-commit). The Release step's drift guard then fails and the
  Release is skipped. Reversing the order silently loses it. Do not restore a `HEAD == origin/main` check.
- **Never overwrite a fix version, and never invent one.** Fill only an empty field; flag a mismatch. If
  `Clarity vX.Y.Z` isn't in `allowedValues`, skip fix versions and note it — no tool creates Jira versions,
  and substituting a nearby one falsifies the record.
- **Transitions go bare and are resolved by name.** Every DEV transition is `hasScreen:false`, so bundled
  `fields` are rejected; set `customfield_10302` separately. `resolution` itself isn't editable in DEV — the
  transition sets it, and if it doesn't, record `MANUAL` rather than retrying.
- **One ticket per signature per release.** Dedupe on `$STATE.triage[].sig`, recording declines too, or a
  persistent error files a ticket on every tick.
- **Spawned sessions use `worktree`, never `inplace`.** `$TARGET` is mid-release: it owns the monitor state
  file and its HEAD is the deployed sha.
- **Never touch the Kamal / QA deploy path.** Prod moves only via the manual SSH `app:deploy`.
- **All prod SSH goes through the sanctioned three** — `prod-ssh.sh deploy` (the only write),
  `prod-ssh.sh verify` (the only ad-hoc read), `pull_logs.sh` (the only log read). Never hand-roll a
  remote command, never add a verb mid-release, and never SSH to *diagnose* — triage evidence comes from
  the pulled log and Datadog only (§Prod SSH contract in [`deploy-cycle.md`](deploy-cycle.md)).
- **Hold one tunnel.** The release operator brings the VPN up once at the start of Phase 6 and takes it
  down after the monitor's final pull; nothing connects or disconnects in between, and every pull uses `--no-vpn`.
- **Alerts are new-only, baseline is pre-deploy.** Fire only on error signatures not in the `SEEN` set;
  seed that set **before** `app:deploy` so a fast-appearing release error can't be pre-marked as known;
  dedupe across ticks (via the state file) so the same error isn't re-reported every cycle.
- **Never invent the version.** The user supplies it; if it's missing, ask and stop.

## What this skill does NOT do

- It doesn't run QA — no provisioning, seeding, QA plans, or agent-qa chaining. That's the
  qa-prep/agent-qa/human-qa pipeline, run before this.
- It doesn't compute or bump the version — the user supplies it.
- It doesn't merge or deploy on red CI, and it won't force-merge, `-f` a tag, or move one. It publishes a
  GitHub Release (`gh release create` with `--generate-notes`); a bare `git tag -a` + push is not the
  cutover.
- It doesn't send the PM email — no send path exists here; it drafts only.
- It doesn't run migrations, apply ClickHouse schema, set prod env, or roll out flags — `app:deploy`
  runs only `git pull` + `composer install` + `composer post-deploy`; anything else is out of scope
  (and was the pre-release readiness report's job). It *does* now surface those as outstanding manual
  steps rather than leaving them silent.
- It **does** write to Jira now (Phase 8b, Phase 9 §Triage) — but it doesn't **create** Jira versions, mark
  a version released, overwrite a fix version someone else set, or move a ticket anywhere but `Done` (and
  `To Do` when re-opening).
- It doesn't fix the errors it files. The spawned session works the ticket; this skill keeps watching.
- It doesn't wait on the `Deployment` run that its own 7b push triggers on `main` — that run is expected
  and benign, and gating the monitor on it would stall the watch for ~13 minutes.
- It doesn't edit `release-prep` or `/document-changes` — it absorbs their logic and leaves them be.

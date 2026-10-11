# Deploy cycle

How `release` Phases 3–7b turn a pushed `main` into a live production deploy: open the RC PR → wait for
`Pint & Tests` → merge into `deploy` → **wait for `Deployment` on `deploy`** → VPN up → SSH prod →
`app:deploy` → gated GitHub Release (tag + release notes) → **sync `deploy` back into `main`**. There are
**two explicit confirms in this file** — **Gate 2** before the merge (§Merge) and **Gate 3** before the prod
`app:deploy` (§Deploy) — plus the gated GitHub Release confirm and a conditional one on a non-fast-forward
sync (§Sync); Phase 0's go-ahead only
authorized getting this far. Hard-stop (no merge/deploy) on any failure **up to and including the deploy**;
§Sync alone never hard-stops, because prod is live by then.

**Two CI gates on different triggers.** `Pint & Tests` (`lint_check_and_test.yml`) is `on: pull_request`
only, so it gates the PR and nothing else. `Deployment` (`deploy.yml`) is `on: push` to `main` and `deploy`,
so it fires *after* the merge — and it is the one that signs and commits `.deploy/*.asc`. Waiting on the
first does not cover the second (§Post-merge CI), and the asymmetry is also why the two branches drift
(§Sync).

Shared vars (from SKILL.md): `$TARGET` (repo dir), `$REL_VERSION` (`vX.Y.Z`), `$HEAD_SHA` (the release
commit on `main`), `$PR_NUM`. Produced here and carried forward: `$MERGE_SHA` (the merge commit on `deploy`,
polled by Phase 5b) and `$DEPLOY_TIP` (`origin/deploy` after the `GHA deploy` auto-commit — the sha prod
should end up on). Prod tooling:

```bash
TLT="$HOME/Herd/clarity/.claude/skills/terminal-log-triage"   # borrowed VPN + log-pull scripts
RSS="$TARGET/.claude/skills/release/scripts/prod-ssh.sh"      # the ONLY way this skill talks to prod
PROD_SSH="ubuntu@10.100.3.142"                                # the prod box, reached over the Insight VPN
PROD_DIR="~/code/platform"                                    # /home/ubuntu/code/platform (documentation —
                                                              # the wrapper carries its own copies)
```

> If the prod IP/target ever changes, update it in **three** places together: `scripts/prod-ssh.sh`,
> the constants here, and `terminal-log-triage/config.local.sh`'s `ssh_target_for()` (the `pull_logs.sh`
> host map).

## Prod SSH contract

Everything this skill does against the prod box goes through exactly **three** sanctioned commands.
There is no fourth:

| Command | Kind | What it is for |
| --- | --- | --- |
| `bash "$RSS" deploy` | **the only write** | Phase 7's `app:deploy` — nothing else ever writes |
| `bash "$RSS" verify` | the only ad-hoc read | deploy-state reconciliation (`git status`/`log -1`/artisan version) — **not** diagnosis |
| `bash "$TLT/scripts/pull_logs.sh" …` | the only log read | path-allowlisted `laravel.log` pulls (baseline + monitor ticks) |

`prod-ssh.sh` takes **verbs, never a raw remote command** — there is no argument that widens it. Both
scripts pin the host, run `-T -o BatchMode=yes -o ConnectTimeout -o ClearAllForwardings=yes
-o ForwardAgent=no`, and fail fast rather than prompt.

**Forbidden, by name** (so no in-the-moment reading of the general rule can admit them): remote
`artisan migrate` / `tinker` / `horizon:terminate` by hand, `composer` anything by hand, editing any
remote file, `systemctl` / service restarts, `rm`, any remote `sudo`, and `scp` / `sftp` / `rsync` in
either direction. If a release genuinely needs one of these, that is an **operator action outside this
skill** — say so and hand it over; never run it from here.

**Why this is strict:** the prod box has **no server-side restriction** — no ForceCommand, no restricted
shell, no documented sudoers policy (verified absent from the repo and `okf/`). The `ubuntu` account can
do anything, so restraint is entirely client-side, and the wrapper's verbs-only interface *is* the
boundary. Never bypass it with a hand-rolled `ssh`, and never add a verb mid-release — a new verb is a
reviewed change to this skill, not an incident response.

---

## PR — open the RC pull request (Phase 3)

Base **`deploy`**, head **`main`**, **non-draft**, title **`RC vX.Y.Z`** (the repo convention — verified
across #284/#295/#303/#319/#324; do not use "release vX.Y.Z"). A **draft** PR does NOT trigger
`Pint & Tests`, so never `--draft` here.

```bash
BODY=$(mktemp)
{
  echo "## Release $REL_VERSION"
  echo
  echo "Merges \`main\` → \`deploy\` for the $REL_VERSION production cutover."
  [ -n "$REL_TICKET" ] && { echo; echo "Release ticket: [$REL_TICKET](https://insightlpr-team.atlassian.net/browse/$REL_TICKET)"; }
  echo
  echo "### Shipped"
  # one line per inventory ticket: - [DEV-XXXX](https://insightlpr-team.atlassian.net/browse/DEV-XXXX) — <title>
} > "$BODY"

PR_URL=$(gh pr create --repo insight-lpr/platform --base deploy --head main \
  --title "RC $REL_VERSION" --body-file "$BODY")
PR_NUM=$(printf '%s' "$PR_URL" | grep -oE '[0-9]+$')
echo "PR=#$PR_NUM $PR_URL  head=$HEAD_SHA"
```

If a PR for `main → deploy` already exists (warm re-run), reuse it (`gh pr list --base deploy --head
main --state open --json number,url`) instead of creating a duplicate; push moved `main`, so its head is
already `$HEAD_SHA`.

---

## CI — wait for `Pint & Tests` (Phase 4)

The gate is the workflow `lint_check_and_test.yml` (workflow + job name both **`Pint & Tests`**): it runs
`./vendor/bin/pint --test` then `php artisan test --compact`. Poll the run tied to **`$HEAD_SHA`** (not
`gh pr checks`, which tracks the head branch tip and drifts once `main` advances). **Run this poll as a
background Bash call** (`run_in_background`), with `$HEAD_SHA` inlined: foreground `sleep` is blocked in
this harness and the 10-min foreground cap would `SIGKILL` a run that takes longer, whereas a background
command runs detached until the verdict and re-invokes you on exit.

```bash
run_for_head() {   # → databaseId|status|conclusion for our commit's pull_request run
  gh run list --repo insight-lpr/platform --workflow lint_check_and_test.yml \
    --json headSha,event,status,conclusion,databaseId \
    --jq "[.[] | select(.headSha==\"$HEAD_SHA\" and .event==\"pull_request\")][0]
          | \"\(.databaseId)|\(.status)|\(.conclusion)\""
}

start=$(date +%s)
no_run_deadline=$(( start + 90 ))        # if NO run appears in 90s → [skip ci] or a draft PR
overall_deadline=$(( start + 20*60 ))    # if a run appears but never completes → hard stop
RUN_ID=""; STATUS=""; CONCL=""; VERDICT=""
while : ; do
  now=$(date +%s)
  row="$(run_for_head)"; RUN_ID="${row%%|*}"; rest="${row#*|}"; STATUS="${rest%%|*}"; CONCL="${rest##*|}"
  if [ -z "$RUN_ID" ] || [ "$RUN_ID" = "null" ]; then
    [ "$now" -ge "$no_run_deadline" ] && { VERDICT="NO_RUN"; break; }   # nothing fired in 90s
    sleep 15; continue
  fi
  [ "$STATUS" = "completed" ] && { VERDICT="$CONCL"; break; }           # success|failure|cancelled|timed_out
  [ "$now" -ge "$overall_deadline" ] && { VERDICT="STILL_RUNNING"; break; }
  sleep 20
done
echo "Pint & Tests → run=$RUN_ID status=$STATUS verdict=$VERDICT"
```

- **`VERDICT=success` →** proceed to Merge.
- **`VERDICT=NO_RUN`** (nothing fired in 90s) → STOP. The release commit likely carries a `[skip ci]`
  token (`git -C "$TARGET" log -1 --format=%B`) or the PR is a draft (`gh pr view $PR_NUM --json
  isDraft`). Fix the cause (re-commit without the token / `gh pr ready $PR_NUM`) and re-enter this phase.
- **`VERDICT=STILL_RUNNING`** (a run appeared but didn't finish in 20 min) → STOP. Don't merge blind —
  check the run on GitHub, extend the wait, or hand back to the user.
- **`VERDICT=failure` →** enter the fix loop below — only a genuine `failure` gets the pint/test fix loop.
- **Any other `VERDICT`** (`cancelled`, `timed_out`, `action_required`, `startup_failure`, `skipped`, an
  empty string, …) → STOP; it is not auto-fixable. Surface it and hand back — never merge.

### Fix loop (red CI) — bounded, mechanical only

```bash
gh run view "$RUN_ID" --repo insight-lpr/platform --log-failed | tail -n 200
```

Classify from the failing step:

- **`Run Pint Check` failed (lint)** → `cd "$TARGET" && vendor/bin/pint` (fixes in place), review, then
  `git add -u && git commit -m "Pint $REL_VERSION"` (no `[skip ci]`). Use `git add -u` (tracked files
  only) — never `git add -A`, which would sweep stray untracked files into an autonomous push to `main`.
- **`Run Unit Tests` failed (phpunit)** → read the failing test, make the **targeted** fix (follow the
  boost guidelines / okf for any code you touch), commit (no `[skip ci]`).

Then re-trigger and re-poll:

```bash
git -C "$TARGET" push origin HEAD:main
HEAD_SHA=$(git -C "$TARGET" rev-parse HEAD)     # PR head follows main; poll the NEW sha
```

**Cap at 2 attempts.** If the failure isn't a clean pint/test fix, or it's still red after the cap,
**STOP** — post the failing logs, leave the PR open and unmerged, and hand back to the user. Never merge
on red.

---

## Merge — into deploy (Phase 5)

On green only, and **behind an explicit confirm (Gate 2)** — show "merge RC #$PR_NUM `main → deploy`?"
and wait for a yes before running the merge. Regular **merge commit** (matches the
`Merge pull request #NNN from insight-lpr/main` history — not squash, not rebase):

**First, re-verify what is about to merge.** Phase 2's push to `main` fired the `Deployment` workflow on
`main`, whose auto-commit advanced `origin/main` past the `$HEAD_SHA` Phase 4 validated — so the PR head is
no longer the sha CI gated. That is benign and expected; **anything else on `main` is not.** Assert that
every commit added since `$HEAD_SHA` is a `GHA deploy` commit:

```bash
# Fresh shell: re-inline $TARGET and $HEAD_SHA (the Phase-2 release commit) literally in this call —
# an EMPTY $HEAD_SHA silently degrades the range and voids the check.
git -C "$TARGET" fetch origin main --quiet
EXTRA=$(git -C "$TARGET" log --format=%s "$HEAD_SHA"..origin/main | grep -vc '^GHA deploy - Run ID: ' || true)
if [ "${EXTRA:-0}" -ne 0 ]; then
  echo "🚨 main gained commits CI never gated — someone else pushed. Do NOT merge; re-run Phase 4 on the new head."
  git -C "$TARGET" log --oneline "$HEAD_SHA"..origin/main
  exit 1
fi
```

> **Do not re-anchor `$HEAD_SHA` to the settled head, and do not require a check on it.** That auto-commit's
> own `Pint & Tests` run is *inconsistent* — it has been observed both as a full 3/3-shard success and as
> `action_required` with **zero jobs**, leaving the head with no check at all and `gh pr checks` reporting
> "no checks reported". Several past releases (#319, #332, #344) merged in exactly that state. A **missing
> or never-executed** check on the auto-commit head is not a red gate; the gate is the green run on the RC
> commit you pushed. Distinguish "never ran" from "ran and failed".

```bash
gh pr merge "$PR_NUM" --repo insight-lpr/platform --merge \
  || { echo "🚨 merge failed — the PR is not mergeable (checks still pending/red, a conflict, or branch protection requires an approval on 'deploy'). Do NOT deploy; resolve and retry."; exit 1; }
git -C "$TARGET" fetch origin deploy --quiet
MERGE_SHA=$(git -C "$TARGET" rev-parse origin/deploy)   # the merge commit — Phase 5b polls THIS sha
echo "merged → deploy @ $MERGE_SHA"
```

> **Assumption:** the `gh` user (`DaltonMcCleery`) can merge `main → deploy` without a *required*
> external review. If `deploy`'s branch protection mandates an approval the operator can't self-supply,
> the auto-merge fails here (fails safe — nothing deploys). If that's the repo's rule, the skill should
> stop after CI-green and hand the merge to a human, then resume at Phase 6.

---

---

## Post-merge CI — wait for `Deployment` on `deploy` (Phase 5b)

> Merging the RC PR pushes to `deploy`, which fires **`Deployment`** (`deploy.yml`; workflow name
> `Deployment`, job `Build and Deploy`). It re-runs pint + tests, GPG-clearsigns
> `.deploy/authorization_deploy.txt.asc` + `commands_deploy.txt.asc`, and **auto-commits them back onto
> `deploy`**. `Pint & Tests` never runs here — it is `on: pull_request` only — so this is a **second,
> independent gate**, not a repeat of Phase 4. And prod's `app:deploy` does a bare `git pull` on `deploy`,
> so that auto-commit must land **before** we deploy or prod ships stale signed authorization files and
> sits a commit behind until the next release.

**Short-circuit first** (warm re-run or a context reset). If the auto-commit is already the tip of
`origin/deploy`, 5b is satisfied — do not poll:

```bash
git -C "$TARGET" fetch origin deploy --quiet
SKIP_5B=0
# BOTH conditions, not just the subject: any PREVIOUS release's auto-commit also matches the prefix.
# Without the ancestor check, a warm re-entry whose merge never actually landed would skip 5b and
# carry the STALE deploy tip toward prod.
if git -C "$TARGET" merge-base --is-ancestor "$MERGE_SHA" origin/deploy 2>/dev/null; then
  case "$(git -C "$TARGET" log -1 --format=%s origin/deploy)" in
    "GHA deploy - Run ID: "*) echo "5b already satisfied"; SKIP_5B=1 ;;
  esac
fi
```

**Run the poll as a background Bash call** (`run_in_background`) with `$MERGE_SHA` inlined — same reason as
Phase 4, and more acute here: measured `Deployment` durations on `deploy` are **12m20s / 12m24s / 12m32s /
13m13s / 13m19s**, all well past the 10-minute foreground cap.

```bash
deployment_run_for_merge() {   # → databaseId|status|conclusion for the merge commit's push run on deploy
  gh run list --repo insight-lpr/platform --workflow deploy.yml --branch deploy \
    --json headSha,headBranch,event,status,conclusion,databaseId \
    --jq "[.[] | select(.headSha==\"$MERGE_SHA\" and .headBranch==\"deploy\" and .event==\"push\")][0]
          | \"\(.databaseId)|\(.status)|\(.conclusion)\""
}

start=$(date +%s)
no_run_deadline=$(( start + 120 ))       # merge push → run creation is near-instant; 120s is generous
overall_deadline=$(( start + 30*60 ))    # p50 ≈ 13 min. Do NOT reuse Phase 4's 20 min — see the note below
DEP_RUN_ID=""; DEP_STATUS=""; DEP_CONCL=""; DEP_VERDICT=""
while : ; do
  now=$(date +%s)
  row="$(deployment_run_for_merge)"
  DEP_RUN_ID="${row%%|*}"; rest="${row#*|}"; DEP_STATUS="${rest%%|*}"; DEP_CONCL="${rest##*|}"
  if [ -z "$DEP_RUN_ID" ] || [ "$DEP_RUN_ID" = "null" ]; then
    [ "$now" -ge "$no_run_deadline" ] && { DEP_VERDICT="NO_RUN"; break; }
    sleep 15; continue
  fi
  [ "$DEP_STATUS" = "completed" ] && { DEP_VERDICT="$DEP_CONCL"; break; }
  [ "$now" -ge "$overall_deadline" ] && { DEP_VERDICT="STILL_RUNNING"; break; }
  sleep 30
done
echo "Deployment (deploy) → run=$DEP_RUN_ID status=$DEP_STATUS verdict=$DEP_VERDICT sha=$MERGE_SHA"
```

> **`headBranch=="deploy"` is load-bearing, not belt-and-braces.** After Phase 7b fast-forwards `main` to
> `deploy`, pushing `main` creates a **second** `Deployment` run with the **same `headSha`** on branch
> `main`. Filtering on sha alone would let a warm re-run latch onto the wrong run.
>
> **Phase 4's 20-minute deadline is wrong here.** This workflow runs `php artisan test --compact`
> **unsharded**, where the PR check shards across three runners. Reusing 20 min would hard-stop a perfectly
> healthy release.

**Then confirm the auto-commit landed** — it is the run's final step, so a completed run means it is
pushed; allow a little fetch propagation:

```bash
# Fresh shell: re-inline $DEP_RUN_ID from the background poll's output line. An empty value would
# collapse the pattern to "any release's auto-commit" — fail loud instead.
[ -n "$DEP_RUN_ID" ] && [ "$DEP_RUN_ID" != null ] || { echo "🚨 DEP_RUN_ID missing — re-read the poll output; do not guess."; exit 1; }
AUTOCOMMIT_OK=0
for i in 1 2 3 4 5 6; do
  git -C "$TARGET" fetch origin deploy --quiet
  SUBJ=$(git -C "$TARGET" log -1 --format=%s origin/deploy)
  case "$SUBJ" in "GHA deploy - Run ID: $DEP_RUN_ID - "*) AUTOCOMMIT_OK=1; break ;; esac   # " - " delimits the id
  sleep 20
done
DEPLOY_TIP=$(git -C "$TARGET" rev-parse origin/deploy)
git -C "$TARGET" show --name-only --format= "$DEPLOY_TIP"   # .deploy/*.asc — plus public/build/* if
                                                           # frontend source changed in this release
echo "autocommit_ok=$AUTOCOMMIT_OK deploy_tip=$DEPLOY_TIP"
```

**Do not wait for a run *after* the auto-commit.** It is pushed with the default `GITHUB_TOKEN`, which does
not trigger workflows — there is no recursion, and a loop waiting for a follow-up run never returns.

Verdicts — **proceed on the first row only:**

| Verdict | Meaning | Action |
| --- | --- | --- |
| `success` + `AUTOCOMMIT_OK=1` | green, signed files on `deploy` | **proceed to Phase 6.** Carry `$DEPLOY_TIP` — prod should `git pull` to exactly this sha |
| `success` + `AUTOCOMMIT_OK=0` | green, commit not visible after ~2 min | **STOP.** Check the run's `Commit build and deploy authorization` step. Deploying now ships stale `.deploy/*.asc` |
| `NO_RUN` | nothing fired in 120 s | **Re-poll once** (a `gh run list` flake or queue latency near the deadline can false-alarm), then **STOP.** The merge commit may carry `[skip ci]` (`git log -1 --format=%B origin/deploy`), or `deploy.yml`'s branch list changed. "No run" is never "fine" — Phase 4's run does not cover `deploy` |
| `STILL_RUNNING` | > 30 min | **STOP.** Check the run, extend, or hand back. Never deploy on an unfinished build |
| `failure` | red | **STOP. Do NOT deploy, and do NOT auto-fix** — see below |
| anything else | `cancelled`, `timed_out`, `action_required`, `startup_failure`, `skipped`, empty | **STOP**, surface, hand back |

> **`action_required` means something different here than on the PR.** `deploy.yml` declares
> `environment: 'deploy'`, so `action_required` on *this* run is the deploy environment awaiting a human
> approval — a real gate, and stopping is correct. That is not the same as the benign never-executed
> `Pint & Tests` run on the auto-commit head described in §Merge; do not conflate them.

> **There is no fix loop here, by design.** Phase 4 could fix-and-repush because nothing had merged yet. At
> 5b the merge is already on `deploy`, and Phase 4's loop pushes to `main` — which cannot fix `deploy`. The
> only clean recovery is a follow-up PR (or a revert). Surface
> `gh run view "$DEP_RUN_ID" --repo insight-lpr/platform --log-failed | tail -n 200` and say so plainly
> rather than looping.

**Gate:** none. 5b is a read-only wait, covered by Gate 2's yes; Gate 3 still guards the deploy.

## Preflight — before touching prod (Phase 6)

The release operator brings the tunnel up **once, here at the start of Phase 6**, in its own short call
(never in the same call as a pull or the deploy), and holds it until after the monitor's final pull.

```bash
# Probe passwordless sudo for wg-quick SPECIFICALLY — a /etc/sudoers.d/wireguard NOPASSWD rule usually
# scopes to wg-quick only, so a generic `sudo -n true` false-negatives. Ask sudo, non-interactively,
# whether it may run this exact binary without a prompt. CAVEAT: if the rule pins ARGUMENTS (e.g.
# `wg-quick up Insight` only), this argless probe can itself false-negative — harmless, it just triggers
# the stop below.
WGQ=$(command -v wg-quick || echo /opt/homebrew/bin/wg-quick)
if sudo -n -l "$WGQ" >/dev/null 2>&1; then SUDO_NOPASS=1; else SUDO_NOPASS=0; fi
echo "passwordless_sudo=$SUDO_NOPASS   (T0 is captured at deploy time, Phase 7)"
[ "$SUDO_NOPASS" = 1 ] && bash "$TLT/scripts/vpn.sh" connect && VPN_WAS=up   # the ONE connect of the release
```

- **`SUDO_NOPASS=0`** — `sudo wg-quick` would prompt, and a non-interactive Bash call **cannot answer a
  password prompt**. **Stop and report** — do not connect, and never try to answer or work around the
  prompt. The fix is a `/etc/sudoers.d/wireguard` NOPASSWD rule for `wg-quick` (added by a human; the skill
  never writes sudoers), then re-probe.
- **After the connect** — record `vpn_was:"up"` and `held_tunnel:true` in the state file (so the deploy
  block below never touches the tunnel and §Stop in [`monitor-loop.md`](monitor-loop.md) still takes it
  down after a context reset). Then, in a **separate** short call, confirm the Mac mini still responds
  before going on; if it doesn't, stop and report.

**Seed the pre-deploy baseline now** (still Phase 6, before Gate 3): run
[`monitor-loop.md`](monitor-loop.md) §Baseline — a read-only `--no-vpn` prod-log pull + a pre-now Datadog query (window coordinator) —
so `SEEN` captures the errors that existed BEFORE this release. Seeding *after* the deploy would let a
fast-appearing release error pre-load into `SEEN` and be silenced for the whole window.

## Deploy — run app:deploy on prod (Phase 7)

`app:deploy` does `git fetch` → `git pull` (fast-forwards the checked-out `deploy` branch to the merge) →
`composer install --no-dev` → `composer post-deploy` (`config:clear`, `optimize`, `pennant:purge`,
`filament:optimize --env=production`, `horizon:terminate`). `--no-confirm` skips its interactive pause.

**Gate 3 — confirm first (point of no return).** State plainly: "about to run
`app:deploy --no-confirm` on production (`$PROD_SSH:$PROD_DIR`) over the held tunnel" and wait for an
explicit yes.

**Capture `T0` in the parent shell, then run the deploy in its own call over the held tunnel** — no
connect, disconnect, or trap in this call (the tunnel came up in Phase 6 and stays up through the
monitor). ⚠️ **Run this Bash call with an explicit timeout of 600000 ms.** `composer install` inside
`app:deploy` routinely exceeds the 120 s default.

```bash
T0=$(date -u '+%Y-%m-%dT%H:%M:%SZ')             # deploy moment (UTC ISO, for Datadog `from`)
T0_EPOCH=$(date +%s)                            # deploy moment (epoch, for tz-safe elapsed math)
# Re-inline the shared vars in THIS Bash call (each call is a fresh shell): RSS/PROD_SSH/PROD_DIR are the
# constants from the top of this file. The tunnel is already held — never connect or disconnect here.
DEPLOY_OUT=$(
  set -uo pipefail
  # The ONE and ONLY write against prod — through the wrapper, never a hand-rolled ssh (§Prod SSH
  # contract). The wrapper bakes in BatchMode + ConnectTimeout: fail FAST if the tunnel/host is
  # unreachable or the host key is unknown, never hang on an interactive prompt.
  bash "$RSS" deploy 2>&1
)
DEPLOY_RC=$?
printf '%s\n' "$DEPLOY_OUT"
```

**If the Bash call was hard-killed** (tool timeout / `SIGKILL`), reconcile before touching anything
else — the remote deploy may have half-finished. The tunnel stays held; if the Mac mini link dropped,
restore the tunnel once in a separate short call (then confirm the mini) and stop and ask if it drops again:

```bash
# Verify remote state READ-ONLY over the held tunnel before ANY retry — never blind-retry app:deploy,
# and never hand-roll the check. The `verify` verb tells you whether the pull landed and the tree is clean:
RECON=$(
  set -uo pipefail
  bash "$RSS" verify 2>&1
)
printf '%s
' "$RECON"
```

Interpretation of `DEPLOY_RC` (the tunnel stays held throughout):

- **`DEPLOY_RC != 0`, or the output shows a git conflict / `composer` error / a missing
  `Deployed!`** → the deploy failed. **Abort — do NOT start monitoring.** Take the held tunnel down
  (`vpn.sh disconnect`, own call). Surface the output and hand back to the user (a failed `git pull` usually means `deploy`
  diverged on the box).
- **Success** (`DEPLOY_RC = 0` and `Deployed!` in the output) → continue. The tunnel stays held for the
  monitor; the gated GitHub Release below publishes to GitHub over the internet, no VPN needed.

### Gated GitHub Release (tag + release notes, confirmed)

Publish a GitHub **Release** for `$REL_VERSION`. That is the primary artifact: it creates the tag (or
uses one that already exists) and attaches auto-generated "What's Changed" notes. **Do not stop at
`git tag -a` + `git push origin`** — a bare annotated tag shows up only on the Tags tab.

Hard laws, unchanged: never `-f`, never move or delete an existing tag. If the Release already exists,
that is **idempotent success** — print its URL and move on. If the tag already exists and the Release
does not, publish against that tag with `--verify-tag` (the v3.10.0 recovery: the tag was already
pushed, then `gh release create v3.10.0 --generate-notes --notes-start-tag v3.9.2 --verify-tag`). Do not
retag.

`$PREV_TAG` is the previous release tag — Phase 1's range base, the last released tag before this
version, the same value as `$STATE.base_tag`.

**The guard is NOT `HEAD == origin/main`** — in the steady state that is *false* here, because Phase 2's
push fired `Deployment` on `main` whose `GHA deploy` auto-commit advanced `origin/main` past local HEAD;
a literal equality check silently skips the Release on every normal release (v3.9.1 shipped untagged
this way). The Release targets `$HEAD_SHA` explicitly, so drift by auto-commits is harmless — assert
instead that the drift is *only* auto-commits (re-use Phase 5's check):

```bash
EXTRA=$(git -C "$TARGET" log --format=%s "$HEAD_SHA"..origin/main | grep -vc '^GHA deploy - Run ID: ' || true)
[ "${EXTRA:-0}" -eq 0 ] || { echo "🚨 main gained non-GHA commits — do not publish the release; investigate first"; exit 1; }
PREV_TAG=$(jq -r .base_tag "$TARGET/.context/release-monitor.json" 2>/dev/null || true)
if [ -z "$PREV_TAG" ] || [ "$PREV_TAG" = null ]; then
  PREV_TAG=$(git -C "$TARGET" describe --tags --abbrev=0 "${REL_VERSION}^" 2>/dev/null \
    || git -C "$TARGET" describe --tags --abbrev=0)
fi
```

**Ask once**, then publish. `--target` is only for creating a missing tag; never pass it with
`--verify-tag`, and never `git tag -f`. `gh release create` prints the Release URL.

```bash
REPO=insight-lpr/platform
if gh release view "$REL_VERSION" --repo "$REPO" >/dev/null 2>&1; then
  gh release view "$REL_VERSION" --repo "$REPO" --json url --jq .url
  echo "release already exists — idempotent success"
elif git -C "$TARGET" ls-remote --exit-code --tags origin "refs/tags/${REL_VERSION}" >/dev/null 2>&1; then
  echo "tag $REL_VERSION already on origin — publishing the Release against it; not moving it"
  gh release create "$REL_VERSION" --repo "$REPO" --title "$REL_VERSION" \
    --generate-notes --notes-start-tag "$PREV_TAG" --verify-tag
elif git -C "$TARGET" rev-parse -q --verify "refs/tags/${REL_VERSION}" >/dev/null; then
  # Local tag only — push it (no -f) and publish the Release against it. Do not create a second tag.
  LOCAL_SHA=$(git -C "$TARGET" rev-parse "refs/tags/${REL_VERSION}^{}")
  [ "$LOCAL_SHA" = "$HEAD_SHA" ] || echo "🚨 local $REL_VERSION points at $LOCAL_SHA, not $HEAD_SHA — not moving it"
  git -C "$TARGET" push origin "$REL_VERSION" \
    && gh release create "$REL_VERSION" --repo "$REPO" --title "$REL_VERSION" \
         --generate-notes --notes-start-tag "$PREV_TAG" --verify-tag
else
  gh release create "$REL_VERSION" --repo "$REPO" --title "$REL_VERSION" \
    --generate-notes --notes-start-tag "$PREV_TAG" --target "$HEAD_SHA"
fi
```

Record `"release":"published"` and `"tag":"$REL_VERSION"` on the monitor state. If the user declines,
print the create command (the `--target "$HEAD_SHA"` form), record `"release":"skipped"` with the same
tag name, and move on. A declined confirm is the only skip; an existing Release or tag is not a failure.

---

## Sync — merge `deploy` back into `main` (Phase 7b)

> `main` and `deploy` each get their **own** `GHA deploy` auto-commit (`authorization_main.txt.asc` vs
> `authorization_deploy.txt.asc` — plus `public/build/*` when frontend source changed), so they drift by a
> commit every release unless `deploy` is merged back.
> Today the drift is one-directional — `git rev-list --left-right --count origin/main...origin/deploy`
> reads `0 2` (the merge commit plus the auto-commit) — so this is normally a **fast-forward**.

**Runs only after a successful deploy** (`DEPLOY_RC = 0` and `Deployed!`) **and after the gated GitHub Release.** The
ordering is not cosmetic: pushing `main` here re-triggers `Deployment` on `main`, whose auto-commit
moves `origin/main`. The Release step allows only `GHA deploy` commits between `$HEAD_SHA` and
`origin/main`; a sync-first merge is not one of those, so the guard fails and the Release is skipped.
**Publish the Release first, sync second.** Do not "fix" the order by restoring a `HEAD == origin/main`
check — that equality is already false in the steady state (it is why v3.9.1 shipped untagged).

Do not switch branches — `$TARGET` may be a worktree whose HEAD merely *equals* `origin/main`. Use the
`HEAD:main` refspec (as Phase 2 does) and probe before merging:

One block, one flag: **`SYNC=go` gates every mutating line** — the guards must actually stop the sync,
not just narrate it. (Fresh shell: re-inline `$TARGET` and `$REL_VERSION`.)

```bash
SYNC=go
git -C "$TARGET" fetch origin main deploy --quiet
[ -z "$(git -C "$TARGET" status --porcelain)" ] \
  || { echo "🚨 tree dirty — sync SKIPPED; set pending.sync_main=skipped"; SYNC=skip; }
if [ "$SYNC" = go ] && ! git -C "$TARGET" merge --ff-only origin/main; then
  echo "🚨 HEAD diverged from origin/main — sync SKIPPED; set pending.sync_main=skipped"; SYNC=skip
fi

if [ "$SYNC" = go ]; then
  if git -C "$TARGET" merge-base --is-ancestor origin/deploy HEAD; then
    echo "deploy already in main — nothing to sync"
  elif git -C "$TARGET" merge-base --is-ancestor origin/main origin/deploy; then
    # The normal path: main is strictly behind → clean fast-forward.
    git -C "$TARGET" merge --ff-only origin/deploy \
      && git -C "$TARGET" push origin HEAD:main \
      || echo "🚨 sync failed (main moved mid-step) — re-fetch and re-enter 7b; never force"
  else
    # main moved after the RC merge → a real merge commit is needed. Probe read-only (git >= 2.38):
    # merge-tree --write-tree reports conflicts without touching the index or the worktree.
    if git -C "$TARGET" merge-tree --write-tree origin/main origin/deploy >/dev/null 2>&1; then
      echo "NON-FAST-FORWARD: merging deploy → main needs a real merge commit."
      echo "STOP — this path requires the fresh confirm below. Do NOT merge or push in this block."
    else
      echo "🚨 deploy → main would CONFLICT — not merging from the skill. Prod is ALREADY LIVE; this is bookkeeping."
      echo "set pending.sync_main=conflict"
      git -C "$TARGET" merge-tree --write-tree origin/main origin/deploy 2>&1 | head -40
    fi
  fi
fi

git -C "$TARGET" fetch origin main --quiet
git -C "$TARGET" rev-list --left-right --count origin/main...origin/deploy   # expect "0 0" (ff) or "1 0" (merge)
```

**The non-fast-forward path stops above by design.** Take the fresh confirm (below) first, and only on a
yes run — as its own call, never folded back into the block:

```bash
git -C "$TARGET" merge --no-edit -m "Merge deploy into main after RC $REL_VERSION" origin/deploy \
  && git -C "$TARGET" push origin HEAD:main \
  || { git -C "$TARGET" merge --abort 2>/dev/null; echo "🚨 sync failed — re-fetch and re-enter 7b; never force"; }
```

**Never hard-stop the release here.** Prod is already deployed and the 2-hour watch matters more than a
branch merge. On a conflict, a dirty tree, or a rejected push: record `pending.sync_main: "conflict"` (or
`"skipped"`) in the monitor state file, print the exact commands for the operator, continue to Phase 8, and
carry the item into the Release Report and the Phase-10 email as an explicit to-do.

**A non-fast-forward sync takes a fresh confirm.** The fast-forward path is announced at Gate 1 and needs no
further yes. But if `main` moved under us, the sync pushes a *merge commit* nobody anticipated — the block
above stops before it; ask once, then run the separate merge+push call on a yes.

**Expect — and never wait for — a `Deployment` run on `main`.** The sync push fires it (~13 min); it
regenerates `authorization_main.txt.asc` and adds one more `GHA deploy` commit to `main`. That is the steady
state, not a failure: the branches end one commit apart again and the next RC PR carries it across. Fire and
forget — never gate the monitor loop on it. Two consequences worth stating: the **next** release's Phase 0
preflight (`HEAD == origin/main`) needs a `git pull` first, and `[skip ci]` cannot suppress this on the
fast-forward path (the pushed tip is the pre-existing `GHA deploy` commit, whose message is not ours to
write).

---

The tunnel stays held (`VPN_WAS=up`, `held_tunnel:true`). Hand off to
[`monitor-loop.md`](monitor-loop.md) with `$T0`, `$T0_EPOCH`, `$VPN_WAS`, `$DEPLOY_TIP`, and the release
inventory — every monitor pull uses `--no-vpn` over it, and §Stop takes it down after the final pull.

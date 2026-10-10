# Dry run

A full rehearsal of `/release` that **performs no prod-affecting action**. It exercises the real
mechanics wherever that's safe and substitutes a read-only proof (or a fixture) exactly where it isn't,
so you can trust the skill before a real cutover — and re-verify it after any change.

Invoke: **`/release vX.Y.Z --dry-run`** (Phase 0 sets `DRY_RUN=1`, and it is also written into the state
file so it survives a context reset). Version is still required (the inventory/changelog need a range), but
nothing lands.

## Safety contract — the eight things a dry run NEVER does
1. **No push to `main`** (Phase 2 writes the files locally, shows the diff, reverts).
2. **No merge into `deploy`** (Phase 5 is stubbed; the throwaway CI PR is *closed*, never merged).
3. **No `app:deploy` on prod** (Phase 7 swaps in a read-only remote command).
4. **No GitHub Release and no tag push.** Phase 7 prints `WOULD: gh release create …` and creates
   nothing — not the Release, and not even a local tag.
5. **No sync push to `main`** (Phase 7b probes read-only and prints `WOULD:`).
6. **No Jira writes** — no transition, no `fixVersions` edit, no comment, no issue create, no re-open, no
   issue link. Phase 8b and §Triage are reads plus `WOULD:` prints, nothing else.
7. **No session spawn** — the `spawn.sh` line is printed, never invoked.
8. **No treating a real `Deployment` run as authorization** — Phase 5b replays a *historical* run rather
   than causing one, because a dry run never merges.

Everything the dry run does touch is read-only, local-and-reverted, or a throwaway it cleans up.
Announce all eight up front; there is no "point of no return," so Gates 2/3/4 and Gate T become
informational `WOULD-confirm` prints, not real prompts. **Gate 1 still gets a yes** — a dry run still opens
a real (throwaway) PR and cycles the VPN, so the user opts in once.

**Enforcement, not convention.** Every write in Phases 7b/8b/§Triage must sit inside an explicit
`if [ "$DRY_RUN" = 1 ]; then echo "WOULD: …"; else …; fi`, and Phase 8b and §Triage must each assert
`DRY_RUN` **before their first tool call** — a `WOULD:` print that happens *after* a `transitionJiraIssue`
is not a dry run. Read `DRY_RUN` from the **state file** — the canonical `.context/release-monitor.json`, which a dry run
also writes (with `"dry_run":1`) — not just the shell: the monitor spans hours and a context reset must
not silently re-arm the write paths.

## Per-phase behavior

| Phase | Dry run does | Proves |
|---|---|---|
| 0 Inputs/preflight | **Real** (read-only) + announce DRY RUN | parse, clean-tree gate, `gh auth`, tooling, sudo probe |
| 1 Inventory + Jira | **Real** (read-only) | range, `rel_filter`, DEV grouping, RC-exclusion, MCP enrichment |
| 2 Version+changelog | **Real, local, reverted** — write files, run coverage check, `git --no-pager diff`, then `git checkout -- composer.json config/changelog.php`. No commit, no push. | the edits + coverage cross-check |
| 3–4 PR + CI | **Real on a throwaway** (below) | `gh pr create`, background poll, the VERDICT machine |
| 5 Merge | **Stub** — print `WOULD: gh pr merge #$PR_NUM --merge`, then **close** the throwaway PR | — |
| 5b Post-merge CI | **Real poll, historical run.** No merge happened, so no new run exists — take the latest real one — sort explicitly, never trust list order: `gh run list --workflow deploy.yml --branch deploy --limit 5 --json databaseId,headSha,createdAt --jq 'sort_by(.createdAt) | reverse | .[0]'`, set `MERGE_SHA` to its `headSha`, run the **real** `deployment_run_for_merge()` poll and assert it resolves on the first iteration with a verdict equal to *that run's own* conclusion (the machinery is under test — a historical run may legitimately be red, or one may be mid-flight during the dry run); then run the **real** auto-commit detector against `origin/deploy`, using the newest **successful** run's id, and assert the head subject matches `GHA deploy - Run ID: <that databaseId> - ` (a red newest run pushes no auto-commit, so the tip belongs to the previous green one) | the jq filter (incl. the `headBranch` guard), the verdict machine, and the auto-commit detector — zero writes |
| 6 Preflight + baseline | **Real** (read-only) — VPN cycle, pre-deploy log pull, Datadog pre-window, seed `SEEN`, write the *dry-run* state file | the baseline path end-to-end |
| 7 Deploy | **Read-only substitute** (below). The gated GitHub Release sub-step prints `WOULD: gh release create "$REL_VERSION" --repo insight-lpr/platform --title "$REL_VERSION" --generate-notes --notes-start-tag "$PREV_TAG" --target "$HEAD_SHA"` — it creates **nothing**, not even a local tag. If a tag for `$REL_VERSION` already exists, the printed command uses `--verify-tag` instead of `--target` | tunnel + host + path + artisan present + the atomic trap lifecycle |
| 7b Sync | **Read-only probe.** `git merge-base --is-ancestor origin/main origin/deploy` (FF vs merge), `git merge-tree --write-tree origin/main origin/deploy` (conflicts, without touching the index or worktree), `git rev-list --left-right --count origin/main...origin/deploy` (the drift). Then print `WOULD: git merge --ff-only origin/deploy && git push origin HEAD:main`. **No merge, no push, worktree untouched** | drift measurement, the FF/merge decision, the conflict probe |
| 8–9 Monitor | **Fixture test + short live smoke** (below) | the alert path + the live plumbing |
| 8b Jira write-back | **Reads real, writes stubbed.** Real `getJiraIssueTypeMetaWithFields` → resolve or miss `Clarity $REL_VERSION` (on a patch release this exercises the **skip** path, which is the live one); real `getJiraIssue` per ticket; run the full deferred-effect classification and print the Gate-4 table; **render every comment body into the transcript**; then `WOULD: transitionJiraIssue(Done)` / `WOULD: editJiraIssue{fixVersions:[…]}` per ticket | version resolution + the missing-version branch, the empty-only fix-version rule, the classifier, the comment templates |
| 9 §Triage | **Fixture-driven.** `App\Fresh` from `tick.log` drives the whole path: evidence extraction, severity, attribution verdict, and the **real** create-vs-re-open JQL probes (read-only). Then print the rendered ticket body, `WOULD: createJiraIssue{…}`, and `WOULD: spawn.sh …`. Assert the **dedupe**: a second tick against the same fixture must not re-render, because `triage[].sig` is already in the dry-run state file. Add a second fixture signature that is present in `baseline.log` and assert it classifies **`unrelated`** with **no** `WOULD: create` | the rubric → attribution → JQL → template → dedupe chain, offline |
| 10 Email | **Real** (draft-only anyway) | the assembled draft |

## Phase 3–4 — throwaway CI PR (real `Pint & Tests`, auto-closed)

Do NOT PR from `main` (that head is real `main`). Instead push a scratch branch and PR *it* into
`deploy` — opening/closing a PR never deploys; only a merge does. The `Deployment`/Kamal workflows fire
on **push** to main/deploy (not on a `dryrun/*` push or a PR), so this only triggers `Pint & Tests` (+ a
harmless teardown check on close).

```bash
TS=$(date +%Y%m%d-%H%M%S); DR_BRANCH="dryrun/release-$TS"
git -C "$TARGET" fetch origin main --quiet
git -C "$TARGET" branch "$DR_BRANCH" origin/main
git -C "$TARGET" switch "$DR_BRANCH"
git -C "$TARGET" commit --allow-empty -m "DRYRUN $REL_VERSION CI probe"   # clean commit, no [skip ci]
git -C "$TARGET" push -u origin "$DR_BRANCH"
HEAD_SHA=$(git -C "$TARGET" rev-parse HEAD)
PR_URL=$(gh pr create --repo insight-lpr/platform --base deploy --head "$DR_BRANCH" \
  --title "[DRYRUN] $REL_VERSION — release-skill CI probe (auto-closed)" \
  --body "Dry-run of the release skill. Do not merge — closed automatically.")
PR_NUM=$(printf '%s' "$PR_URL" | grep -oE '[0-9]+$')
git -C "$TARGET" switch -                                                 # back to the original branch
```

Then run the **real** §CI poll (`deploy-cycle.md`) as a background call against `$HEAD_SHA`, and assert
`VERDICT=success`. **Optional fault-injection sub-tests** (more CI minutes; do them if asked):
- **NO_RUN** — amend the probe commit with `[skip ci]` in the message, force-push, re-poll → expect
  `VERDICT=NO_RUN` in 90s.
- **failure + fix loop** — add a deliberate pint violation (e.g. a double space), push → expect
  `VERDICT=failure`; then run the real fix-loop steps (`gh run view --log-failed`, `vendor/bin/pint`,
  `git add -u`) and confirm it would re-push. Revert.

Cleanup for this phase is in §Cleanup (close PR + delete branch, both remotes).

## Phase 7 — read-only deploy substitute

Same atomic, trap-guarded block as the real deploy (so the tunnel lifecycle is genuinely exercised),
but the remote command is **read-only** — it never calls `app:deploy`:

```bash
T0=$(date -u '+%Y-%m-%dT%H:%M:%SZ'); T0_EPOCH=$(date +%s)
echo "WOULD run on prod: cd $PROD_DIR && php artisan app:deploy --no-confirm"
PROBE_OUT=$(
  set -uo pipefail
  if [ "$VPN_WAS" != up ]; then
    trap 'bash "$TLT/scripts/vpn.sh" disconnect >/dev/null 2>&1 || true' EXIT INT TERM
    bash "$TLT/scripts/vpn.sh" connect >&2 || { echo "🚨 VPN failed to come up." >&2; exit 97; }
  fi
  bash "$RSS" verify 2>&1     # the wrapper's read-only verb — this also exercises prod-ssh.sh
                              # end-to-end before a real release relies on it
)
PROBE_RC=$?
printf '%s\n' "$PROBE_OUT"
```

Pass = `PROBE_RC=0`, the remote path exists, and `php artisan --version` responds (so `app:deploy` is
runnable). Also assert the wrapper's **rejection** path offline first: `bash "$RSS" nonsense` must exit 2
with the usage line and touch no network — the verbs-only boundary, proven without a wire. This proves everything about the deploy step except the deploy itself — which cannot be
rehearsed (no staging deploy target is wired; the Kamal `pre-platform` host is deliberately out of
scope). Say so plainly in the summary.

Then print the gated GitHub Release and **do not run it** (`$PREV_TAG` is Phase 1's range base,
`$STATE.base_tag`):

```bash
echo "WOULD: gh release create \"$REL_VERSION\" --repo insight-lpr/platform --title \"$REL_VERSION\" --generate-notes --notes-start-tag \"$PREV_TAG\" --target \"$HEAD_SHA\""
```

If `refs/tags/$REL_VERSION` already exists locally or on `origin`, print the same command with
`--verify-tag` instead of `--target "$HEAD_SHA"`. Create nothing — not the Release, not a local tag.

## Phase 8–9 — monitor: fixture (alert path) + short live smoke

A live read-only run usually reports "clean", which proves plumbing but never the **alert** path — so
do both.

**Fixture (deterministic, offline):** the read-only mandate forbids writing to prod logs, so inject the
"new error" via fixtures and run the real signature-diff + dedup logic against them.

```bash
DR="$TARGET/.context/dryrun"; mkdir -p "$DR"
printf '[2026-08-13 10:00:00] production.ERROR: known {"exception":"App\\\\Known"}\n' > "$DR/baseline.log"
{ cat "$DR/baseline.log"; printf '[2026-08-13 10:31:00] production.ERROR: new bug {"exception":"App\\\\Fresh"}\n'; } > "$DR/tick.log"
```
Seed `SEEN` from `baseline.log` (→ `{App\Known:1}`), run a tick against `tick.log`: assert `App\Fresh`
is flagged **new** and alerts; run a *second* tick against the same file: assert `App\Fresh` is now in
`SEEN` and does **not** re-alert. That's the whole alert+dedup contract, proven without prod.

**Live smoke (real, compressed clock):** run 2–3 real ticks with `next=5s`/`10s` instead of `5m`/`20m`
— real `pull_logs.sh` pull + Datadog query each tick — writing the **canonical** state file
(`.context/release-monitor.json`) with `"dry_run": 1` set. **No separate `.dryrun.json` path**: the
wake/resume and "stop monitoring" recognizers read only the canonical path, so a split file would mean a
mid-smoke context reset comes back reading *nothing*, defaults `dry_run` to 0, and silently re-arms the
write paths — the exact failure the Enforcement paragraph exists to prevent. `"dry_run":1` in the file IS
the disarm; cleanup removes the file. Confirm the cadence math, state read/write, VPN cycling, and
clean/alert reporting all work, then **stop after ~3 ticks** (do not run the 2h loop).

## Cleanup — guaranteed

Run at the end and on any abort:
- Revert the changelog/version edits: `git -C "$TARGET" checkout -- composer.json config/changelog.php` (if still dirty).
- Close + delete the throwaway PR/branch: `gh pr close "$PR_NUM" --repo insight-lpr/platform --delete-branch` (deletes the remote branch); also `git -C "$TARGET" branch -D "$DR_BRANCH"` locally.
- Restore the VPN to `$VPN_WAS` (the Phase-7 block's trap already did, but double-check `vpn.sh status`).
- Abort any stray merge and prove HEAD never moved:
  `git -C "$TARGET" merge --abort 2>/dev/null || true` then echo `git -C "$TARGET" rev-parse HEAD` and
  confirm it equals the sha recorded at Phase 0.
- **Jira write audit** — state explicitly that zero Jira write tools were called (no
  `transitionJiraIssue` / `editJiraIssue` / `addCommentToJiraIssue` / `createJiraIssue` /
  `createIssueLink`), and zero sessions were spawned.
- Remove dry-run scratch: `rm -rf "$TARGET/.context/dryrun"`, and the state file **only if** it carries
  `"dry_run": 1` (`jq -e '.dry_run==1' … && rm …`) — never delete a real run's monitor state.
- Delete any local tag a mis-step created: `git -C "$TARGET" tag -d "$REL_VERSION" 2>/dev/null || true` —
  a leftover local tag makes the *real* release push that tag and take `--verify-tag` instead of
  `gh release create --target`, and it corrupts the next inventory range if it points at the wrong
  commit. The dry run must not call `gh release create` — there is no remote Release to delete.

## Pass criteria (report a ✅/❌ checklist)

`inventory built` · `changelog+coverage (reverted)` · `throwaway PR opened + Pint & Tests VERDICT=success`
· `PR closed + branch deleted` · `5b poll + auto-commit detector verified against a historical run` ·
`prod-ssh.sh rejects unknown verbs offline` ·
`VPN up→prod-ssh.sh verify→down, artisan present` · `7b drift + conflict probe, HEAD unmoved` ·
`baseline seeded` · `8b version resolved-or-skipped, Gate-4 table + comment bodies rendered` ·
`monitor fixture: new alerts, dedup holds` · `triage: severity + attribution + JQL probes + ticket body
rendered, unrelated fixture files nothing, dedupe holds` · `live smoke: 3 ticks clean plumbing` ·
`email draft renders` · `nothing pushed to main/deploy, no app:deploy, no GitHub Release, no tag, zero Jira writes, zero
sessions spawned`.

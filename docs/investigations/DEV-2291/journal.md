2026-08-03 07:34 CDT · intake · intake complete: route=bug app=clarity base=main
2026-08-03 08:16 CDT · prepped · prep complete (escalated best-of-N): root cause = ShouldBroadcastNow zone events throw inside save() transaction; gate=BLOCKED on 2 design Qs

## Phase 2 gate interview (2026-08-03, normal mode)
Gate was BLOCKED (2 needs-human design questions). Interview with Dalton:
- Q (fix approach): ShouldRescue vs try/catch vs revert-to-queued → **ShouldRescue on the two events, keep ShouldBroadcastNow.**
- Q (failure UX): silent+logged vs soft warning → initially "soft warning", but on surfacing that ShouldRescue swallows the failure inside the framework (warning impossible without try/catch plumbing) → **pure ShouldRescue, silent + logged** (broadcast failures report()'d to error tracking; no admin-facing warning).
Resolved via code (no human needed): impersonation is incidental (real Auth::loginUsingId); error fires on Save not on live-select (no afterStateUpdated); a metadata-only edit on an already-assigned zone would also fail.
Gate recomputed → CLEAN.
2026-08-03 08:34 CDT · gated · gate CLEAN: Dalton chose ShouldRescue (keep ShouldBroadcastNow), silent+logged
2026-08-03 08:36 CDT · planned · plan: 1 node — add ShouldRescue to ZoneAssignmentsChanged+ZoneUpdated + TDD tests
2026-08-03 08:38 CDT · provisioned · provision-only: isolated Postgres reused (warm), CH deferred+neutralized, PostGIS patched
2026-08-03 08:47 CDT · implemented · implement: ShouldRescue on ZoneAssignmentsChanged+ZoneUpdated (keep ShouldBroadcastNow) + resilience test (throwing broadcaster) + marker guards; 128 Zone tests green
2026-08-03 08:54 CDT · reviewed · review RN=1: 100/100, 1 lane (in-session adversarial-reviewer; review.sh nested claude -p not-logged-in), no scoring findings
2026-08-03 09:17 CDT · verified · verify: agent-qa 6/6 green (assign user to zone → no error toast, persists); backend confirms ShouldRescue; PASS
2026-08-03 09:23 CDT · verified · handoff parked: human-qa guide generated; awaiting Dalton's QA (PR draft, env up)
2026-08-03 09:56 CDT · done · done: PR #299 ready + In Code Review, Build Record posted, QA env torn down

## Post-done: CI status (honest note)
The final `done` commit triggered Pint & Tests, which FAILED — but not on this change.
3 failures, all pre-existing ClickHouse tests unrelated to DEV-2291, reproduced identically on 2 attempts:
- tests/Feature/RdnMetrics/Clickhouse/RdnHitHourCountQueryTest (x2) — CH counts all 0
- tests/Feature/Services/Scout/BuildShiftSummaryTest — totalScans 0 vs 3
Why it's not DEV-2291: the diff is only `implements ShouldRescue` on 2 zone event classes; in CI BROADCAST=log (never throws) so ShouldRescue is a no-op; neither failing test references the changed classes. Both failing test files are UNCHANGED since the last green main run (2026-07-24). The base (29101af1, 2026-08-02) sits on multiple [skip ci] CH commits, so CI never validated current main — PR #299 is the first to run CI on this base and surface the breakage. 1368 passed incl. all new zone/broadcast tests. Spun off a separate investigation (spawn_task) to fix the base CH tests. DEV-2291 fix itself is unaffected.

## Post-done: merged main's base CH fix
The 3 failing ClickHouse tests were confirmed a base regression and fixed on main via PR #301
(fix/ch-tests-main): "re-anchor ClickHouse hot-table fixtures to the current clock" (ed7bdb42) —
exactly the stale-fixture-clock root cause. Merged origin/main (acddec57) into this branch
(clean, zero overlap with the ShouldRescue diff) and pushed to re-run CI. The zone/broadcast fix
is unchanged.
2026-08-03 13:08 CDT · done · merge origin/main (base CH fix #301) into branch to clear unrelated CI red; re-run CI
2026-08-03 13:23 CDT · done · remove stray handoff doc (belongs to the base-CH-fix task #301, not DEV-2291) from the PR

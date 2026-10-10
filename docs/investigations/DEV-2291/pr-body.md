<!-- ticket-status -->
**Ticket:** [DEV-2291](https://insightlpr-team.atlassian.net/browse/DEV-2291)
**Phase:** done · **Route:** bug · **Mode:** normal
**Done:** intake · prep (escalated best-of-N) · gate (CLEAN) · plan · provision · implement (ShouldRescue + TDD) · review (100/100) · verify (agent-qa 6/6, PASS) · human QA (Dalton) · handoff (Build Record posted, → In Code Review, QA env torn down)
**Fix:** `implements ShouldRescue` on ZoneAssignmentsChanged + ZoneUpdated (keep ShouldBroadcastNow) — a broadcast delivery failure is report()'d + swallowed per-dispatch, so ManageZones::save()'s transaction never rolls back on it. Silent + logged.
**Open questions:** none
**Resolved questions:**
- Fix approach → ShouldRescue on both events, keep ShouldBroadcastNow · human:Dalton 2026-08-03
- Failure UX → silent + logged (no soft warning) · human:Dalton 2026-08-03
- Impersonation causal? → no, incidental · code
- Error on live-select or Save? → Save · code
**Artifacts:** docs/investigations/DEV-2291/
<!-- /ticket-status -->

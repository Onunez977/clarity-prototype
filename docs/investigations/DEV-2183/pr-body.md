<!-- ticket-status -->
**Ticket:** [DEV-2183](https://insightlpr-team.atlassian.net/browse/DEV-2183)
**Phase:** done · **Route:** bug · **Mode:** normal
**Done:** intake · prep (escalated) · gate (CLEAN) · plan · implement (TDD) · review (96/100) · verify (local QA skipped per request; CI runs the ClickHouse suite) · handoff
**Next:** merge after CI green + optional human QA
**Open questions:** none
**Resolved questions:**
- Guard value → `>= 3` (relax `> 3`), matches dashboard-stats · human:Dalton 2026-07-13
- Shared query → **hardcode `>= 3` for all callers** (param dropped as over-engineered; the non-Scout KitUserPathController heatmap also moves to `>= 3` — marginal, accepted) · human:Dalton 2026-07-13
- Sibling queries → keep narrow (order query only); check-in/dashboard `> 3` divergence flagged as follow-up · human:Dalton 2026-07-13
**Artifacts:** docs/investigations/DEV-2183/
<!-- /ticket-status -->

---

## DEV-2183 — Scout Map plate-length guard (`>= 3`) on direct & staging order queries

Aligns the Scout Map **order** density layers (Direct + Staging) with the dashboard-stats
`length(plate) >= 3` rule so addresses with no actionable plate are excluded.

### What changed
- **`app/Clickhouse/Queries/HotlistAddressGeohashQuery.php`** — the plate guard changes from
  `length(plate) > 3` to `length(plate) >= 3` (one line). This single query serves the Direct
  (`staging=false`) and Staging (`staging=true`) order layers, so both ACs are covered by the
  one edit.
- **Tests** — `seedGeohashCell` gains a `$plate` param; three tests pin the boundary
  (query-level length-3-in / length-2-out, plus the Direct and Staging endpoints surfacing a
  length-3 plate).

### Scope note
`HotlistAddressGeohashQuery` is shared by a third caller, `KitUserPathController` (the
non-Scout repo user-path heatmap). We first parameterized the guard to keep that caller on
`> 3`, but opted to **hardcode `>= 3` for everyone** — the param was over-engineered for a
value that never varies, and the heatmap change is marginal (only exactly-length-3 plates).

### Out of scope (gate decision)
`ScoutCheckInRollupQuery` and the `ClarityScoutDashboardController` stat queries stay on
`> 3`. Moving the order query to `>= 3` re-introduces a small check-in-vs-order-map
divergence (DEV-2163 had matched them at `> 3`) — flagged as a follow-up, not fixed here.

### Testing
Local test run + agent/human QA skipped by request (backend-only, one-line SQL guard).
Pint + `php -l` clean. CI runs the `RequiresClickhouse` suite
(`ClarityScoutMapControllerTest` order tests) on this main-based PR.

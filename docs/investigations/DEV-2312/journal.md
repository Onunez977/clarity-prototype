2026-08-06 12:55 CDT · intake · intake complete: route=bug app=clarity base=main
2026-08-06 13:05 CDT · prepped · prep complete: gate=BLOCKED (root cause needs console error / repro); wiring mapped, 3 hypotheses refuted
2026-08-06 13:06 CDT · prepped · gate=BLOCKED parked: awaiting console error + repro context (3 open questions)

## Phase 2 — Question Gate interview (2026-08-06, human:Dalton)
- Q1 (console error text?) → A: `AxiosError: Request failed with status code 500` on the `/app/hits` Livewire request (plus separate per-cluster init 500/502 on `/geocode/reverse` and `/nearest-given-address`). The 500 is the map action throwing server-side.
- Q2 (CH vs PG path?) → A: Admin impersonator AND `clickhouse:hits` enabled → ClickHouse-backed hits path.
- Q3 (map blank vs nothing?) → A: Nothing appears (Livewire 500s before dispatching map data).
- ROOT CAUSE (confirmed by code): CH mode → Table::getModel()=getQuery()->getModel()::class → App\Clickhouse\Models\Hit; sidebar-filters mounts TableMapComponent with that model + relationship='scans'; formatRecords() hops `$record->scans` which the CH Hit model lacks → RelationNotFoundException → 500. Gate → CLEAN.
- Scope note: /geocode/reverse (502) + /nearest-given-address (500) are separate init-time endpoints; flagged out-of-scope pending Dalton (recommend follow-up ticket).
2026-08-06 13:29 CDT · planned · gate CLEAN (root cause confirmed); plan: 1 node, 2-file CH-safe fix + CH Hit map accessors + regression test
2026-08-06 13:32 CDT · provisioned · Postgres isolated env verified (branch+testing DBs migrated); tiger patched; CH neutralized+deferred
2026-08-06 13:44 CDT · implemented · fix applied (guard relationship hop + CH Hit map accessors); 5 tests pass; mechanism corrected to null->empty->no-dispatch; in-memory validated pins
2026-08-06 13:55 CDT · implemented · review RN1 82/100: fixed HIGH hot/cold gap (findManyAcrossWindows across hot+cold, FINAL+dedup); tests green
2026-08-06 14:00 CDT · reviewed · review passed RN2 100/100 (RN1 82 -> hot/cold gap fixed); functional policy; non-scoring nits -> PR comment
2026-08-06 14:07 CDT · verified · verified against real CH: pre-fix empty, post-fix 2 pins; 3/3 ACs SATISFIED (server-level repro)
2026-08-06 14:10 CDT · verified · parked at handoff — awaiting human QA; env wired + guide rendered (.context/qa-human-guide.html)
2026-08-06 14:28 CDT · verified · agent-QA browser lane PASS: map opens with 2 pins, click /update=200; server-level + live-browser both green
2026-08-07 07:49 CDT · done · DEV-2312 complete: fix shipped, PR #313 ready + In Code Review, Build Record posted, QA env (CH container) reclaimed
2026-08-07 07:52 CDT · done · revert boost-regen PHP-version drift (production is 8.3) in .junie/guidelines.md, AGENTS.md, CLAUDE.md
2026-08-07 08:42 CDT · done · trim: inline hot+cold merge into fetchRecords, drop ClickhouseModel::findManyAcrossWindows (behaviorally identical, tests green)
2026-08-07 08:54 CDT · done · adversarial re-review RN3 100/100 (post-trim, token-equivalent); reviewed_sha=HEAD; rework+build-record gates green
2026-08-07 09:02 CDT · done · simplify per owner: cold is complete (hot=90d subset) -> map queries cold-only + FINAL, drop hot+cold span
2026-08-07 09:06 CDT · done · RN4 100/100 (cold-only + FINAL per owner data model); reviewed_sha=HEAD; gates green

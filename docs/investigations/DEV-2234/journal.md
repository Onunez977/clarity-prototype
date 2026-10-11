2026-07-24 10:16 CDT · intake · intake complete: route=feature app=clarity base=main
2026-07-24 10:28 CDT · prepped · ultra-prep: gate=BLOCKED; A/B fork + shared-flag scanPaths finding + precision-6 trap; exhaustiveness confirmed

## Phase 2 — Question Gate interview (normal mode) — 2026-07-24

Gate was BLOCKED on two material questions. Presented to Dalton:

Q1 (scope A/B): "Remove only the UI control (A) or full removal incl. backend precision path, config flag, satellite swap (B, recommended)?"
A1: **B — full removal** of the popover UI + backend precision param path + satellite basemap swap.

Q2 (shared flag): "scanner_debug_map_enabled also gates scanPaths() per-hour scan-path debug coloring (DEV-2018, no UI tie). Remove it too, keep the flag for scanPaths only, or preserve on a renamed flag?"
A2: **Keep the flag for scanPaths only** — leave config/app.php scanner_debug_map_enabled in place; do NOT touch scanPaths()/HistoricPathBuilder/HistoricPathBuilderTest. Full Option B applies to the popover/precision/satellite path only.

Two code-answered items (no human needed): (a) Bird's Eye controller uses the same resolveDebugPrecision() trait method → updated in the same change; (b) precision hardcoded to 7 (CH query classes fall back to 6 when the param is absent), so the AC "precision-7 unchanged" holds.

Gate recomputed: CLEAN. Proceeding to plan.
2026-07-24 10:47 CDT · gated · gate resolved: scope=B, keep scanner_debug_map_enabled for scanPaths only
2026-07-24 10:48 CDT · planned · plan: single-node Option B removal + keep-flag; no blast-radius; perf N/A
2026-07-24 10:52 CDT · provisioned · provision-only: isolated Postgres zod_claude_dev_2234 + testing DB, dev-login, CH deferred
2026-07-24 11:08 CDT · implemented · implement t1: remove Scout Debug control (Option B), hardcode precision=7 (2 controllers), keep scanner_debug_map_enabled for scanPaths; 88 ClarityScout tests green; assets rebuilt
2026-07-24 11:10 CDT · implemented · revert boost-doc PHP-version churn (8.4.23->8.3) accidentally committed by bootstrap/boost regen
2026-07-24 11:16 CDT · reviewed · review RN1: 3 lanes 100/100, zero scoring defects; non-scoring notes posted to PR
2026-07-24 11:23 CDT · verified · agent-QA PASS: AC-1/2/3 satisfied (browser 4/4 + built-bundle/live-shell + 88 tests)
2026-07-24 11:24 CDT · verified · handoff park: agent-QA PASS; human-QA guide rendered; awaiting Dalton QA
2026-07-24 13:09 CDT · done · DEV-2234 complete: Debug map control removed (Option B), PR #292 ready + In Code Review, Build Record posted, QA env torn down

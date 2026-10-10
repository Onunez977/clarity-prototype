# Journal — DEV-2595
- 2026-09-21T13:28:22Z intake: route=bug app=clarity base=main (default; no stacked Scout date-filter candidate)
- 2026-09-21T13:28:22Z knowledge: loaded clarity/scout.md (path_globs match ClarityScout*/clarity-scout)
- 2026-09-21T13:28:22Z precedents: birdseye-disposition-filters-cache / shared-dashboard-date-range-wiring applicable (BirdsEye range+cache / shared date-range wiring); org-map-aggregate-labeling / org-map-historical-paths-pan-zoom not-applicable; parent scout-beta-feedback-epic
- 2026-09-21T13:28:22Z flags: no_spawn=true (single-session run per operator)
2026-09-21 08:28 CDT · intake · intake complete: route=bug app=clarity
- 2026-09-21T13:31:17Z prep: root cause = BirdsEye recentHits limit(20); driver already uncapped (95da4d7f). gate=BLOCKED (fix shape + CH pending cardinality).
2026-09-21 08:31 CDT · prepped · prep complete: gate=BLOCKED root=BirdsEye recentHits limit(20)
- 2026-09-21T13:33:48Z gate: Q1→remove limit(20) match driver (Dalton). Q2→proceed without CH pending cardinality (Dalton). gate=CLEAN.
2026-09-21 08:33 CDT · gated · gate CLEAN: remove BirdsEye recentHits limit(20); envelope approved
- 2026-09-21T13:34:20Z plan: single simple node t1 — remove BirdsEye recentHits limit(20) + regression test
2026-09-21 08:34 CDT · planned · plan: t1 remove BirdsEye recentHits limit(20) + test
- 2026-09-21T13:36:31Z implement t1: removed BirdsEye recentHits ->limit(20); test_recent_hits_multi_day_ranges… passed (9 assertions)
2026-09-21 08:36 CDT · implemented · implement t1: remove BirdsEye recentHits limit(20) + regression test
- 2026-09-21T13:38:05Z review RN1: REVIEW_GATE pass (in-session L1); non-scoring residuals only (MAX_MAP_HITS, stale comment)
2026-09-21 08:38 CDT · reviewed · review RN1 pass score=100 (in-session); residuals non-scoring only
- 2026-09-21T13:45:28Z verify: QA PASS — last_7/15/30 return OLDERHIT (26 plates); org-map 200; PHPUnit recent_hits green
- 2026-09-21T13:45:28Z handoff: Build Record posted; PR undrafted; Jira → In Code Review; phase=done
2026-09-21 08:45 CDT · done · handoff: QA PASS; Build Record posted; PR ready for review
2026-09-21 08:45 CDT · done · revert bootstrap Boost churn in AGENTS.md/CLAUDE.md
- 2026-09-21T13:51:08Z agent-qa: browser lane qa-i9v0-1 PASS (A1–A6); OLDERHIT visible on 7/15/30 Days, absent on Today
2026-09-21 08:51 CDT · verified · agent-qa browser PASS: Live Hits shows OLDERHIT on 7/15/30 Days
2026-09-21 09:10 CDT · done · human QA complete; QA env torn down

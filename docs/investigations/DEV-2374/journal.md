2026-08-14 10:05 CDT · intake · intake complete: route=feature app=clarity base=main; decision-only (KUP != Scout Admin Org Map)
2026-08-14 10:07 CDT · implemented · implemented: decision-only — KUP != Org Map; R1 rejected for KUP; R2/R3 left on DEV-2341; posted to DEV-2222/2341/PR#320
2026-08-14 10:07 CDT · verified · reviewed+verified N/A: decision-only; parked awaiting human QA before undraft/Build Record
2026-08-14 10:08 CDT · verified · record status mirror comment id 38269
2026-08-14 10:13 CDT · gated · scope corrected to Org Map→KUP option B; blocked on render-budget question
2026-08-14 12:19 CDT · gated · gate CLEAN: historical Org Map continuous GPS + kit chunking; Live out of scope
2026-08-14 12:23 CDT · implemented · implement: Org Map historical paths use continuous sc_positions (KitRangePathsQuery); client chunks by 25 kits; Live untouched
2026-08-14 12:34 CDT · implemented · fix review RN2 scoring: org-scale 50m dedup, splice path_json, cached countPoints, 422 over-budget, atomic chunk merge, fixture spacing
2026-08-14 12:45 CDT · reviewed · review parked: RN3 MIN 72 after one fix cycle; residual HIGHs on bbox pushdown, aggregate budget, silent 422 skip, serial abort; rerun_required=true
2026-08-14 15:45 CDT · implementing · residual rework: strategy-B bbox pushdown, fleet 1.5M budget, parallel waves+incremental publish; PG-024 OOS (chlocal)
2026-08-14 15:58 CDT · implementing · RN4 scoring fixes: full-remaining wave budget, clear-on-empty, isolate chunk errors, skip until roster; remove PII probes
2026-08-14 16:28 CDT · reviewed · review parked: RN5 MIN 64 after one fix cycle; residual HIGHs on wave over-fetch + toast/wipe; rerun_required=true
2026-08-17 07:28 CDT · implementing · authorized RN5 fixes: sequential budgeted chunks, no wipe on 500, header parse, roster defer/retry, segments try/catch
2026-08-17 08:04 CDT · implementing · RN6 follow-ups: continue past fat chunks, clear stale on truncate, gate deferred retry on bbox
2026-08-17 08:36 CDT · reviewed · review parked: RN7 MIN 62 after authorized fix cycle; residual build+toast/wipe+countPoints fanout; rerun_required=true
2026-08-17 08:46 CDT · implementing · authorized RN7 fixes: rebuild public/build, separate loadError vs budget toast/no-wipe, single handle() budget count, path_json guard, drop timings.lock
2026-08-17 08:46 CDT · implementing · restore .gitignore order; keep .timings.lock ignored
2026-08-17 09:15 CDT · reviewed · review parked: RN8 MIN 70 after authorized RN7 pass; residual post-assembly budget + budget-exhausted chunk churn; rerun_required=true
2026-08-17 09:31 CDT · implementing · provision isolated Herd worktree for Agent QA (clarity-claude_dev_2374.test)
2026-08-17 09:32 CDT · implementing · record isolated Herd worktree path in state (clarity-claude_dev_2374.test)
2026-08-17 09:33 CDT · implementing · revert bootstrap boost-doc regen (AGENTS/CLAUDE/.junie) — keep worktree provision metadata only
2026-08-17 09:52 CDT · verifying · agent QA PASS: 6/6 browser + 5/5 backend; AC-1..4 satisfied; yellow: camera vs seed bbox

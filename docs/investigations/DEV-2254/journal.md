2026-09-01 13:40 CDT · intake · intake complete: route=bug app=clarity base=main
2026-09-01 13:55 CDT · prepped · prep complete (best-of-3 reconciled): gate=BLOCKED, 4 open questions; scope=App.vue+MapStatsBar.vue label/copy, backend unchanged

## Phase 2 — Question Gate interview (2026-09-01, human:Dalton McCleery)

Q1. Exact wording and visual treatment for the aggregate label — "All Drivers" vs "Aggregate" vs "Org-wide", as a persistent pill in the existing slot?
A1. "All Drivers". Treatment confirmed as offered: a persistent pill in the same slot as the existing "Showing only {name}'s stats" pill, but neutral grey rather than crimson — crimson reads as "you are narrowed", and the aggregate state is the default.

Q2. Does the subset-selected-but-not-followed state (e.g. 3 of 10 drivers in the Driver Selector) get its own label, or is "All Drivers" vs "Showing only X" the only distinction wanted?
A2. Yes — label all three scope states. The subset state gets its own label, e.g. "Showing 3 of 10 drivers". Same mechanism, closes the identical misreading risk.

Q3. Is the MapStatsBar tooltip copy fix in this PR? Today those tooltips read "The total number of license plates your kit read..." and "Plates you scanned..." unconditionally, including for an admin reading an org-wide total.
A3. Yes — fix the copy in this PR. Branch the four description strings on adminMode using the DashboardOverlay ternary idiom. Driver surface copy stays exactly as-is.

Q4. DashboardOverlay.vue:85-88 hardcodes "Org-wide hits that haven't been repossessed yet" whenever adminMode is true, even while a driver is followed or a subset is selected. Same bug pointing the other way. Fix here, or separate ticket?
A4. Yes — fix it in this PR. Its Live Hits header must reflect the ACTUAL scope: the followed driver's name when following, and org-wide only when genuinely unfiltered.

Gate recomputed: BLOCKED -> CLEAN. Target files now App.vue + MapStatsBar.vue + DashboardOverlay.vue (+ rebuilt public/build).
2026-09-01 13:58 CDT · gated · gate CLEAN: all 4 questions answered by human; scope now App.vue + MapStatsBar.vue + DashboardOverlay.vue
2026-09-01 14:00 CDT · planned · plan: single node t1 (complex) — statsScope getter in map.js, 3-state pill, adminMode-branched tile copy, scope-aware Live Hits header
2026-09-01 14:05 CDT · provisioned · provisioned isolated env: PG branch DBs migrated, local Docker CH on literal clarity/zod, Cloud CH unwired, tiger patch + baseline reseed
2026-09-01 14:10 CDT · implemented · implement t1: statsScope getter + 3-state scope pill + adminMode-branched tile copy + scope-aware Live Hits caption; build green, 50/50 backend tests pass
2026-09-01 14:23 CDT · implemented · RN1 review 94/100: fix the one scoring finding (DashboardOverlay heatmap tip still said 'your kit' to admins) + 3 non-scoring (pill tied to showStatBar, aria-live, comment trim, alias removed)
2026-09-01 14:35 CDT · implemented · RN2 review 94/100: fix both scoring findings — gate the heatmap tip so the DRIVER string is byte-restored (RN1 fix had leaked to the PWA), and revert the committed boost-doc regen (okf-lint now passes, AGENTS.md guard restored)
2026-09-01 14:47 CDT · reviewed · review complete: RN1 94 -> RN2 94 -> RN3 100, gate pass; 3 scoring findings fixed across rounds; non-scoring posted to PR
2026-09-01 15:12 CDT · verified · agent QA: 6/6 ACs SATISFIED, no red findings. Lane1 7/8, Lane2 4/4 (driver PWA byte-identical), backend 3/3. One yellow: select-all wording, for human QA
2026-09-01 15:17 CDT · verified · human QA guide rendered; parked awaiting human QA
2026-09-01 15:43 CDT · verified · QA-loop reword (Dalton's call): subset pill -> 'Filtered to N driver(s)', caption matched, driverNoun in the getter for the plural rule; re-verified live 4/4
2026-09-01 15:52 CDT · verified · RN4 review 100/100 on the reword; reviewed_sha re-baselined
2026-09-01 15:57 CDT · verified · QA guide re-rendered for the reword (rev 3); aqa-1 resolved
2026-09-02 08:27 CDT · done · human QA complete; PR undrafted, Build Record posted, Jira In Code Review, QA env torn down

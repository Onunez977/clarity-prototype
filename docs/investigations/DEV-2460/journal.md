2026-09-01 15:12 CDT · intake · intake complete: route=feature app=clarity base=main
2026-09-01 15:36 CDT · prepped · prep complete (best-of-N: 4 passes + backstop): gate=BLOCKED, 18 settled, 3 open questions

## Question gate — DEV-2460 (normal mode, 2026-09-01)

Q1. HotlistCount 5->6 stats: flat int 6, or responsive? (theme.css min-h-40 = 160px was
    calibrated against the row AT 5 COLUMNS; its comment says "Revisit here if the top row's
    copy changes.")
A1. RESPONSIVE (default 1 / sm 2 / md 3 / xl 6). Re-check the 160px floor by eye in human QA
    and update the theme.css calibration comment to describe the 6-column reality.
    -- human:Dalton 2026-09-01

Q2. Moving Active Kit Users to the header row removes its click-to-expand table of active kit
    names (header stats have no expand affordance). Acceptable?
A2. ACCEPTED. The mockup shows it as a plain header stat. Must be recorded EXPLICITLY in the
    PR and the Jira Build Record as a deliberate consequence, not left unremarked. KitUsers.php
    is retained so the table can be rehomed later.
    -- human:Dalton 2026-09-01

Q3. Should RecoveryCounts::getDatasetLabel() ('Repossessions', the chart data-series name)
    also become 'Total Repossessions'?
A3. YES. One line; keeps the internal series name and the visible card title in agreement.
    -- human:Dalton 2026-09-01
2026-09-01 16:04 CDT · gated · gate CLEAN: 3 questions answered by operator
2026-09-01 16:16 CDT · planned · plan: single node, 10 ordered steps, 7 excluded sites; sizing gate fired + split rejected with reason
2026-09-02 07:52 CDT · provisioned · provision: Postgres isolated + migrated; CH deferred (eager-skip). Baseline 25 pass / 10 fail, all AC-relevant tests GREEN
2026-09-02 08:06 CDT · implemented · implement: 7-card mockup order, 2 relabels (both RecoveryLeader sites), Kit Users -> HotlistCount slot 5/6 responsive, Pro-gate seam (gate BEFORE toStat), getCardLabel moved to trait w/ guard. 32 pass vs 25 baseline, same 10 pre-existing fails
2026-09-02 08:38 CDT · implemented · review round 2 fixes (scores 88/87/88 -> all 7 scoring + 10 non-scoring findings addressed); seam coverage mutation-verified; 35 pass vs 25 baseline
2026-09-02 09:01 CDT · implemented · review round 3 fixes (91/92/89): camera-kits label corrected per okf kit_type rule, cardWidgets() + fail-soft + locked-key + RecoveryCounts relabel now pinned (4 mutations verified), lg regression reverted to one row. 40 pass vs 25 baseline
2026-09-02 09:19 CDT · implemented · round 4 fixes: cardWidgets() pin was a TAUTOLOGY (now drives the real Dashboard, mutation dead); fail-soft test was vacuous in CI (now an injected throwing double); interface contract destaled; getColumns pinned; false coverage record corrected. 42 pass vs 25 baseline
2026-09-02 10:01 CDT · reviewed · review round 5 PASS (90/92/91, min 90). Fixed a FABRICATED 'Human QA confirms' claim + 2 false docblocks + 3 record errors; pinned LOCKED_VALUE literal; stated one unkillable mutation honestly
2026-09-02 14:25 CDT · provisioned · qa-prep: env provisioned (PG reused, CH docker literal clarity/zod), seeded + widget-verified, both plans written, ledger recorded
2026-09-02 14:26 CDT · provisioned · qa-prep: env provisioned (PG reused, CH docker literal clarity/zod), seeded + widget-verified, both plans written, ledger recorded
2026-09-02 14:39 CDT · verified · agent QA PASS — 22/22 items, 7/7 ACs, 0 defects in the diff. The 4 zero-headline hit cards were a missing hits_cold seed source (headline is uniqExactIf(ocr) from hits_cold; hit_stats_daily has no plate column), not an AC-6 regression. Corrected my ~112px header-squeeze estimate to the measured ~140-150px.
2026-09-02 15:20 CDT · verified · review round 6 (92/92): fixed a REAL layout defect this ticket caused — stat labels overhung their card by 16px at 1024px (min-width resolving to min-content below a ~110px card; 5 cols sat on the threshold). overflow-wrap:anywhere, row-scoped. break-word was tried first and is a no-op here — only anywhere affects min-content sizing; an A/B caught it before it shipped as a non-fix. Also killed the 'unkillable' mutation via getComponent(), made LOCKED_VALUE final, and corrected 5 false quantitative claims of my own.
2026-09-02 15:23 CDT · verified · record Build Record comment 39017; re-stamp reviewed_sha at the round-6 fix commit
2026-09-02 15:25 CDT · verified · trigger PR validation on a clean head
2026-09-02 15:33 CDT · verified · AC-7 measured properly (36 vs 36 per cold render, base vs HEAD, via the CH container's query_log — static call-site inspection was only a proxy); untrack the .timings-pending.json sidecar that had been riding into the PR
2026-09-02 15:34 CDT · verified · reconcile: replay the AC-7 measurement commit onto the rebased remote tip (branch was rebased onto main w/ DEV-2254; no work discarded)
2026-09-02 15:37 CDT · verified · human-QA guide rendered (6 ok / 1 warn / 0 bad); re-baseline plan shas after the reconcile
2026-09-02 15:43 CDT · verified · CI green on c6a43a6e — Pint & Tests 3/3 shards, verified at job level
2026-09-02 15:44 CDT · verified · re-stamp reviewed_sha to the rebased round-6 commit (770440a0); both gates green
2026-09-03 07:57 CDT · verified · human QA caught a real defect I introduced: header labels broke MID-WORD below 1280px (lg:6 gave a 44px label box at 1024 vs an 87px word; md:3 compounded it inside a pre-existing half-width container). Columns now set from measurement — xl 6 / lg 3 / md 2 — 0 breaks at 6 widths. Retracted AC-1's false 'one row, legible' claim and the overflow-wrap rule's billing as a fix (now a documented backstop); rewrote a test that asserted the wrong conclusion.
2026-09-03 08:07 CDT · verified · operator rejected the first fix and was right: I had retuned columns inside a half-width container and called the container out of scope. Real cause — Filament normalises a SCALAR columnSpan to ['lg'=>span], and BOTH dashboard widgets used the string 'full', so each was half-width below 1024. Explicit per-breakpoint arrays on both, header columns re-derived against the corrected container (md 3 / lg 3 / xl 6). Header+grid now 95-97% of content width, 0 mid-word breaks across 8 viewports. Pinned columnSpan in a test.
2026-09-03 08:37 CDT · verified · round 8 (88/100) fixes: REAL defect at exactly 1280px — 3 labels broke mid-word on a 0.2px deficit my detectors' float tolerance hid; fixed by trimming this row's padding to 20px/side (0 breaks at 13 viewports). Simplified both columnSpans to the minimal ['default'=>'full'] and replaced the property-spelling test with one asserting Filament's own gridColumn output for BOTH widgets. Corrected an INVERTED sweep: AdminClickhouseStatsWidget is dead (0 subclasses) while 3 client-panel widgets carry the live bug. Moved ~100 lines of narrative to docs/investigations/DEV-2460/layout-findings.md; fixed a wrong denominator, a wrong baseline figure, a conflated viewport and a .gitignore comment.
2026-09-03 09:06 CDT · verified · review round 9: 92/100, gate PASSED. Fixed the two numbers my own padding fix invalidated (the 1280px sentence described a surplus not a deficit; the >=135px/1282px six-abreast threshold is really ~127px/~1240px), removed a self-contradictory height list, completed the sweep record, refreshed the stale qa-ac-results AC-1 evidence, and moved the padding to @apply px-5. Stopping here — the gate had already passed and the rest was churn.
2026-09-03 09:10 CDT · done · QA complete: env torn down (seeded rows only, shared CH DBs preserved), QA summary posted (39090), PR commented. Live verify corrected the Build Record's camera-kit claim from ~26% to 1.7%.
2026-09-03 09:18 CDT · done · retarget PR #383 base main -> company-metrics (intake missed the DEV-2409 epic branch); flagged the 5 DEV-2254 files that ride along because company-metrics is 1 behind main

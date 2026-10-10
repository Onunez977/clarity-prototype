# DEV-2058 — Scout: Hit modal scaling/overlap fix + vehicle info

Append-only run journal. Never edit or reorder existing lines.

## Phase 0 — Intake
- app=clarity (platform), route=feature (Task), mode=normal, jira_mirror=on
- base-branch resolution: candidates=[scanner-app (ahead 149)]; resolver (haiku) → base=scanner-app, confidence=95 (depends on DEV-2052 modal work, In Code Review on scanner-app, not yet in main). Adopted (>=80).
- branch=claude/DEV-2058 off origin/scanner-app, isolated worktree.
- precedents: DEV-2052 (Direct/Staging modal — dependency), DEV-2053 (split hit stat), DEV-2055 (duplicate hit markers).
2026-06-18 10:47 CDT · intake · intake complete: route=feature app=clarity base=scanner-app
- intake outward: pushed claude/DEV-2058; draft PR #204 (base scanner-app); Jira status-mirror comment id=30961.

## Phase 1 — Ultra-Prep (sonnet)
- modal = full-viewport teleport overlay in DayOverlay.vue ~242–283 AND TripOverlay.vue ~156–197 (same modal — both need the fix).
- vehicle fields: year/make/model + hotlist_us_state already in hits_cold (add to ClarityScoutTripsController SELECT + payload); vehicle_color is in PG ScanMetaData (secondary lookup by scan_guid).
- DEV-2052 type-label pattern (MapShell.vue) reused for Live/Staging labeling.

## Phase 2 — Gate: BLOCKED (parked, normal mode)
- Q1 (design approval, feature route): reposition approach (bottom-sheet / top-anchored / computed offset) + confirm field set (year+make+model+state).
- Q2 (data-semantics): include vehicle_color via PG ScanMetaData lookup, or defer and ship year/make/model+state from ClickHouse only?
- Parked for Dalton. Resume after answers via /ticket DEV-2058 --resume (or answer inline).
- ANSWERED (dalton 2026-06-18): Q1 → reuse existing BottomSheet.vue (bottom-28/z-30, floats above the bottom-nav pill at bottom-8/z-40 — clears it, no overlap/z-fight; matches the Route Overview sheet already in DayOverlay); fields = year/make/model + hotlist_us_state, labeled by hit_type. Q2 → DEFER vehicle_color (CH-only, no PG lookup). Gate → CLEAN.
2026-06-18 10:52 CDT · prep · ultra-prep done; gate=BLOCKED (design approval + vehicle_color scope)
2026-06-18 11:02 CDT · gate · gate CLEAN: bottom-sheet via BottomSheet.vue, defer color

## Phase 3 — Ultra-Plan (opus)
- DAG: t1 backend (controller day()/show() + test), t2 frontend (DayOverlay+TripOverlay → BottomSheet). Disjoint, parallelizable, no depends_on. blast_radius none.

## Phase 4 — Implement (opus workers, parallel)
- worktree bootstrapped (composer/npm install, branch DB, herd site clarity-claude_dev_2058.test). Dev seed FAILED on tiger.state.simplified_geom (PostGIS patch qa-prep applies, not the bootstrap hook) — deferred to QA.
- t2 frontend: hit lightbox → <BottomSheet :open="!!selectedHit"> in DayOverlay + TripOverlay; added hitTypeLabel badge (L→Direct Live, S→Staging Live) + vehicle row (year make model + state, v-if guarded); removed dead modalBackdrop/watch/nextTick. npm run build PASS (963 modules).
- t1 backend: +vehicle_year/make/model/hotlist_us_state to hits_cold SELECT + hits_detail payload in day() & show(); day() cache key → :v2 (show() uncached); extended ControllerTest (nullable-aware, day + show). pint PASS. phpunit deferred (env tiger patch).
- unexpected-files guard: reverted Laravel Boost regen (AGENTS.md/CLAUDE.md/copilot-instructions/junie/boost.json) — kept out of diff. Build assets (public/build/*) committed as legit output.
2026-06-18 11:11 CDT · implement · implement: hit modal → BottomSheet (+ type label + vehicle row); controller vehicle fields + tests
2026-06-18 11:16 CDT · review · fix RN1 scoring defects: type-label key (hit_type→type) + Teleport sheet out of inert root

## Phase 5 — Review (adversarial-reviewer; deviation: agent not review.sh)
- RN1 → 90/100. Two Medium defects: (1) badge key mismatch (Vue read hit_type, payload key=type → AC-2 badge never rendered); (2) BottomSheet under :inert root → undismissable (AC-1 interaction). Both fixed (RN1 commit a103089d).
- RN2 → 100/100 (verified in built artifact). Gate PASS (>=95). Residual: 2 cosmetic non-scoring (leading space when year null; test clock near-midnight) → fold into Build Record.
2026-06-18 11:20 CDT · review · review RN2 100/100 — gate PASS

## Phase 6 — Verify (QA pipeline: qa-prep + agent-qa)
- qa-prep: local CH docker up (clarity-qa-clickhouse; .env moved OFF ClickHouse Cloud → 127.0.0.1; backup saved); tiger patch forced (no qa.config.json); baseline PG seed OK; CH hits_cold task-seeded (4 L + 2 S incl 1 null-vehicle).
- agent-qa: 2 browser lanes + backend. A1–A9 ✅ (bottom-sheet not full-screen, marker visible, vehicle row + Direct/Staging badge, null-guard, mobile 390px), B1–B4 ✅ (day()/show() carry vehicle keys + type; CH L=4/S=2; S filtered). AC-1 + AC-2 SATISFIED. 0 blocking.
- 🟡 non-blocking: pre-existing ScannerMap.vue:301 "Trip NaN" tooltip (out of scope); clarity-scout-trips Pennant flag not seeded by QaSeeder.
- Findings: real ClickHouse Cloud creds were in the worktree .env (up.sh neutralized); platform missing .claude/qa.config.json (postgis/migrate).

## Phase 7 — Handoff: PARKED at human QA (normal mode)
- human-qa guide rendered: .context/qa-human-guide.html (5 happy-path + 2 edge + 2 agent findings). Env all green.
- PARKED for Dalton's manual sign-off. On "I've completed QA": fold qa-complete summary into the Build Record, build-record-gate, transition Jira, then qa-complete teardown-only. NOT done yet.
2026-06-18 11:56 CDT · verify · QA verify: qa-prep(docker CH)+agent-qa PASS, AC-1/AC-2 satisfied; parked at human QA

## Phase 7 — Handoff: DONE
- Human QA signed off ("I've completed QA"). PR #204 undrafted + retitled (no [WIP]).
- Build Record posted (comment 30994, folds QA summary). DEV-2058 → In Code Review (manual, transition 4). build-record-gate: pass (ac=2, review_rounds=2).
- QA teardown (qa-complete teardown-only): CH DBs + container removed, PG branch DBs dropped, herd site unlinked, .env restored, ledger deleted. Receipt: .context/qa-complete.md.
- state.phase=done.
2026-06-18 12:06 CDT · done · handoff complete: Build Record posted, In Code Review, QA torn down

# DEV-2113 — Scout: Historical map icons must match left pane (open hits only)

2026-06-26 · intake · app=clarity route=bug mode=normal
2026-06-26 · intake · base=scanner-app (Scout ticket; scanner-app carries Scout work DEV-2111, ahead=190 behind=0; recorded convention: Scout PRs stack on scanner-app)
2026-06-26 · intake · precedents: DEV-2052 (Direct/Staging cluster markers), DEV-2055 (->final() marker dedup), DEV-2080 (canonical order-data / hotlist_status), DEV-2053 (Direct/Staging stat split), DEV-2111 (live-hit map labels)
2026-06-26 · intake · knowledge cards: none matched (no clarity/scout card for ScannerMap.vue / ClarityScoutMapController.php)
2026-06-26 13:50 CDT · intake · intake complete: route=bug app=clarity base=scanner-app
2026-06-26 13:58 CDT · prepped · ultra-prep complete: backend already filters Open (DEV-2080); gate=BLOCKED on 3 requirements questions; deep reconcile skipped (requirements-driven escalation, code claim driver-verified)
2026-06-26 · gate · Q answered (human): real bug remains (logo markers); AC-2 = same open-only RULE (not record parity); timing unsure -> reproduced live
2026-06-26 · gate · ROOT CAUSE (live CH repro, read-only): hits_hot.hotlist_status is a per-row snapshot frozen at hit-write, never back-updated. Map markers leak because they (1) include Staging Live ('S') rows -- almost always stamped Open (16021 open vs 1302 closed) and rarely re-scanned, (2) no plate dedup, (3) filter hotlist_status=1 at raw-row level. Leak is 91% Staging; 216/218 problem plate-keys are Staging. Pane/heatmap escape only by being Direct-only. Dedup-by-latest alone insufficient (staging rarely gets a closing row).
2026-06-26 · gate · Authoritative current status = clarity.hotlist.status (CDC-synced, FINAL); hits_hot.hotlist_id joins clarity.hotlist.id. Fix fork: A) Direct-only historical markers (regresses DEV-2052) vs B) filter markers by CURRENT hotlist status via join/semi-join to clarity.hotlist (correct, preserves staging) -- escalating to human.
2026-06-26 14:15 CDT · gated · gate CLEAN: root cause reproduced (frozen per-row hotlist_status; 91% staging leak); fix=current-status semi-join to clarity.hotlist; per-hit markers kept
2026-06-26 14:18 CDT · planned · plan: single-node t1 — current-status semi-join in ClarityScoutMapController@hits + test updates; no blast-radius
2026-06-26 14:22 CDT · provisioned · provisioned real local ClickHouse (clickhouse-qa --also-testing + literal clarity/zod DBs); existing marker tests run+pass on real CH
2026-06-26 14:22 CDT · provisioned · provisioned real local ClickHouse (clickhouse-qa --also-testing + literal clarity/zod DBs); existing marker tests run+pass on real CH
2026-06-26 14:30 CDT · implemented · implement t1: current-status semi-join in ClarityScoutMapController@hits (clarity.hotlist FINAL via hotlist_id); tests updated + Staging regression added; 50/50 pass on real CH; pint clean
2026-06-26 14:46 CDT · review · review RN1 fixes: H2 (marker status label from current-open fact), H3 (distinct candidate pluck); H1/H4 (recentHits+heatmap divergence) deferred to DEV-2117 per human decision
2026-06-26 14:54 CDT · reviewed · review: RN2=83 (held down only by deferred siblings -> DEV-2117); marker code confirmed correct; added multi-CDC-version regression test; 51/51 pass
2026-06-26 15:11 CDT · verified · verify: seeded Scout QA env + human-qa guide; endpoint check returns 4 OPEN-* markers, 0 REPO-*; AC-1/2/3 satisfied; parking for human QA
2026-06-26 15:18 CDT · done · DONE: Build Record posted (32551), PR #234 ready + In Code Review, QA env torn down, follow-up DEV-2117 filed

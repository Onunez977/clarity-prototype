2026-07-24 10:14 CDT · intake · intake complete: route=bug app=clarity base=main; recall=4 precedents (DEV-2176 closest); frontend-only disposition row-removal bug
2026-07-24 10:30 CDT · prepped · prep complete (escalated best-of-N): root cause confirmed; gate=BLOCKED on 2 material questions (scope + AC-1/backend-filter consistency); arg-position + null-guard + grouped-DOM constraints recorded

## Phase 2 — Question Gate interview (normal mode) — 2026-07-24
Q1 (SCOPE): Fix strictly to Missed Opportunity, or apply to the shared changeDisposition() for Scans/Hits too?
A1: Missed Opportunity only. Gate the new rowKey behind this.missedOpportunity. — human:Dalton 2026-07-24

Q2 (DATA SEMANTICS / AC-1): MissedOpportunity default view excludes ONLY Invalid (pinned by test_default_view_excludes_only_invalid_dispositions). Valid/Reviewed reappear on refresh if optimistically removed. (a) remove all 3, (b) only remove on Invalid, (c) widen backend filter?
A2: (b) Only remove on Invalid — match the backend. Pass the rowKey only when the selected value is the Invalid disposition; Valid/Reviewed stay put. Backend filter + regression test UNCHANGED. Narrows AC-1 to the Invalid status. — human:Dalton 2026-07-24

Gate recomputed: CLEAN.
2026-07-24 10:48 CDT · gated · gate=CLEAN: scope=Missed-Op-only, remove-on-Invalid-only; backend filter unchanged
2026-07-24 10:51 CDT · planned · plan: single simple subtask (disposition-popup.blade.php); rowKey gated missedOpportunity+INVALID, 4th-arg, null-guarded; app.js unchanged
2026-07-24 10:56 CDT · provisioned · provision-only Postgres: bootstrap → isolated zod_claude_dev_2233 (+testing), PostGIS-patched + seeded; CH deferred (host neutralized); site clarity-claude_dev_2233.test
2026-07-24 11:01 CDT · implemented · implement t1: null-guarded rowKey on changeDisposition gated missedOpportunity+INVALID (4th arg, reason stays 'other'); CH-free render test 2/2 green (red→green confirmed); pint clean; blast-radius clean; app.js untouched, no rebuild
2026-07-24 11:09 CDT · reviewed · review RN1 1 lane harness-claude-p score 94 gate pass; 1 residual test-coverage note deferred to Phase 6 QA; non-scoring findings posted to PR

## Phase 6 QA-driven change — 2026-07-24
agent-QA confirmed the core fix (Invalid removes live [A2], Valid/Reviewed don't [A3]) but found the Missed-Op default view is AWAITING-only (pre-existing: MissedOpportunityResource forDispositions([]) → whereNull; excludeDisposition(Invalid) branch dead in the UI). Surfaced to Dalton; premise of the Q2 "Invalid only" decision was false. Dalton chose: remove on ANY disposition. Broadened the client gate to `this.missedOpportunity` only (dropped `&& value===Invalid`). Test updated (2/2 green), pint clean. Re-review RN2 + re-verify Phase 6 to follow. AC-1 delta: Invalid-only → all three statuses.
2026-07-24 12:11 CDT · implemented · QA-driven: broaden MO disposition removal to ANY status (missedOpportunity gate only); test updated 2/2; re-review RN2 next
2026-07-24 12:25 CDT · verified · verify PASS: agent-QA on real CH confirmed removal on any disposition (5/5, POST 200, no reload, reload-empty); AC-1/AC-2 SATISFIED, AC-3 deferred to human QA (code-proven); pre-existing default-filter bug filed separately
2026-07-24 12:31 CDT · verified · handoff parked human-QA guide rendered env green awaiting human QA PR stays draft env up

## Phase 7 — Human QA complete — 2026-07-24
Dalton ran manual QA against the standing env: Missed-Op single + bulk disposition, Scans (Invalid Scan / AC-3), Hits (unchanged). QA-driven env/seed corrections during the park (local harness only, no app-code change): (a) CH write creds + CLICKHOUSE_DATABASE_CLARITY fixed; (b) reseeded CH scans_hot/hits_hot keyed to REAL org-2 Postgres scan ids (2/3/25/26 scans; scan_id 37/38/49 hits) after the first seed used fabricated ids that 500'd DispositionController::firstOrFail. Bulk disposition confirmed a separate server-side path (BulkDispositionAction) untouched by the fix. Dalton signalled "I've completed QA". No new code since RN2 (reviewed_sha 17350763).
2026-07-24 13:35 CDT · done · done: fix shipped (remove MO row on any disposition); RN2 review 90 pass; agent+human QA PASS; Build Record posted; In Code Review; QA env torn down

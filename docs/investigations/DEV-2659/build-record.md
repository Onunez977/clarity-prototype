# Insight AI — Build Record

> Mirror of Jira comment **41825** on DEV-2659 (posted 2026-09-24 19:46:29 CDT). Tip-fix rn=2 Verify after Agent QA PASS on product tip `3b9bc75…`.


**Sprint 41 · Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/465 (**OPEN**, undrafted, QA Ready)

## TL;DR

Claims and repos funnels now read `clarity.billing_claim_events_v` with `is_internal = 0`. The orgs CTE and insightlpr needle joins are gone. Shared dedup triple lives in `BillingClaimDedup`. Tip-fix after Agent QA FAIL on stale product tip `ccfbc676…`; suites and Review rn=2 ran on product tip `3b9bc75…` (≥ docblock fix `1d6e2b89…`). Hold vs [DEV-2654](https://insightlpr-team.atlassian.net/browse/DEV-2654) is **lifted** (PR #436 merged). Item 5 / AC-7 stays out of ship.

## WHAT changed

* `UniqueLprCaseClaimsQuery` and `LprRepossessionsQuery` read `{clarity_db}.billing_claim_events_v`; alias `org_id` → `forwarder_org_id`; filter `is_internal = 0`.
* Deleted orgs CTE, insightlpr needle `LEFT JOIN`s, `kept`, `JSONExtract*`, and `public_api_keys` from these statements.
* New `BillingClaimDedup` owns the shared triple `(forwarder_org_id, agent_org_id, vin)` and the repos `deduped` CTE text. Claims uses `uniqExactIf` on that triple in the same SELECT as `all_claims` / `successful`.
* Docblock trail: classes do not read `clarity.orgs`; exclusion is `is_internal = 0` (no `type IN (1, 2)` docblock — no orgs path remains).
* Tip-fix: docblock assert hygiene in `1d6e2b89…`; `tip_sha` point `3b9bc75…` so Agent QA does not checkout refused tip `ccfbc676…`.

## WHY

Both funnels were scanning unprunable `zod.public_billing_events` with `JSONExtract*` + `public_api_keys` + double orgs needle joins. Comment [41325](https://insightlpr-team.atlassian.net/browse/DEV-2659?focusedCommentId=41325) proved the denormalized view matches claims output and collapses cost (~1,771× fewer rows on the measured window). After `is_internal = 0` replaces the needles, the orgs CTE has no readers — plan deleted it rather than swapping to `clarity.orgs`. Prior Agent QA FAIL was checkout of stale product-only tip missing the docblock fixes already on the branch.

## HOW numbers were derived

* Agent QA (2026-09-24 ~19:44 CDT): Pint **PASS 10 files**; PHPUnit **189 passed / 0 fail / 0 skip / 707 asserts**; `CLICKHOUSE_DRIVER=clickhouse` @ `127.0.0.1:18126`; classic `clarity`/`zod` + keyed `clarity_dev_2659` (table_count **31**); PG `:15435` `zod_testing_dev_2659`; `cloud_touched=false`; neighbor `:18124` / `:18125` untouched; RequiresClickhouse tip suites **0 skips**; both prior `BillingClaimDedupTest` failures **GREEN**
* Review rn=2: scores **[95, 92, 93]** · min 92 · `reviewed_sha=3b9bc75aa083137f85a69302483c8c95acb5fbf8` · `rerun_required=false` · act_on NONE · light tip-fix after FAIL on `ccfbc676`
* Thin conventions #16: conventions lane **92**; Pint PASS 10; OKF wiki lag soft OK
* Ancestry: `1d6e2b89` ⊂ `3b9bc75` ⊂ PR head `4584b5a` (docs Review stamp only). Refused Verify against `ccfbc676` alone.
* Cloud ClickHouse: READ-ONLY forever; no writes this ticket. Fleet env left **UP**.
* **Cloud old-vs-new parity (2026-09-25 ~07:35 CT, READ-ONLY Production):** Jul 2026 UTC platform-wide `case-claim` only.
  * Claims (`UniqueLprCaseClaimsQuery`): OLD `zod.public_billing_events`+needles and NEW `clarity.billing_claim_events_v`+`is_internal=0` both **15128 / 14288 / 11975**; successful non-blank VIN triple set-diff **0/0**.
  * Repos (`LprRepossessionsQuery`): both **deduped 11975 / db_repos 9149**; deduped `(forwarder, agent, vin, claim_date)` set-diff **0/0**.
  * Evidence file: `docs/investigations/DEV-2659/cloud-parity-jul-2026.md`.

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 | SATISFIED | Both funnels read `billing_claim_events_v`; no `JSONExtract*` / `public_api_keys` / orgs needle joins in these statements |
| AC-2 | SATISFIED (seeded + Cloud re-measure) | Fleet Docker seeded arithmetic + `X-ClickHouse-Summary` path; Cloud Jul 2026 re-measure 2026-09-25: new path **24,576 read_rows** vs old **~46.3M** on the claims window (magnitude still aligns with comment 41325 org-165 cite). |
| AC-3 | SATISFIED (plan) | Met by deleting `clarity.orgs` from these queries; no bare orgs join source remains |
| AC-4 | SATISFIED | Single-pass: one `billing_claim_events_v` reference per statement; repos outer SELECT reads `deduped` once (suite + Review) |
| AC-5 | SATISFIED | Shared `BillingClaimDedup` triple + repos `deduped` CTE |
| AC-6 | SATISFIED (Cloud re-measure 2026-09-25) | Jul 2026 UTC platform-wide old vs new exact: claims **15128 / 14288 / 11975** (0 triple diffs); repos **deduped 11975 / db_repos 9149** (0 triple+claim_date diffs). See `docs/investigations/DEV-2659/cloud-parity-jul-2026.md`. Comment 41325 was the prior cite; this is the live Cloud READ-ONLY confirmation. |
| AC-7 | OUT OF SHIP | Item 5 deferred (scope lock 41330); not a done-gate |
| AC-8 | SATISFIED | Sentinel guards kept (`agent_org_id != 0`, hotlist `id`/`hotlist_id != 0`); fixture rows at sentinel in tip suites |
| AC-9 | SATISFIED (plan) | Docblock: no `clarity.orgs` read; exclusion `is_internal = 0` (supersedes original type IN (1,2) trail) |
| Preflight #18 | SATISFIED | `clickhouse` · `:18126` · 189/707/0 skip · `cloud_touched=false` · Agent QA cite |
| Live browser | NOT_COVERED | curl `/app/login` 200 only — soft |

## Soft flags (non-blocking)

* Live NOT_COVERED (auth/OTP dashboard not exercised)
* SHOW CREATE VIEW open/non-blocking (view present on classic @ `:18126`; body prove soft)
* OKF `okf/concepts/billing-events.md` wiki still names legacy `zod.public_billing_events` (wait for approval)
* Stale `kept` method-doc prose in UniqueLprCaseClaimsQuery (SQL correct)
* AC-7 / Item 5 out of ship

## What this ticket did NOT do

* Item 5 / drop `forwarder_org_id` from the uniq key
* Cloud ClickHouse writes
* Undraft / QA Ready / merge / Status Board from Verify
* Touch neighbor fleet ports `:18124` (2654) or `:18125` (schema-drift)
* Authenticated live dashboard smoke

## Review / conventions

Review rn=2 · 95 / 92 / 93 · min 92 ≥ 85 · reviewed_sha `3b9bc75…` · rerun_required=false. Thin conventions #16 PASS (conventions 92; Pint 10; OKF soft).

## PR

https://github.com/insight-lpr/platform/pull/465 — **OPEN**, undrafted, QA Ready · product tip `3b9bc75aa083137f85a69302483c8c95acb5fbf8` · PR head `4584b5a81e8cad301055e396517a77840cadf519`

## Next

**Undraft / CI / QA Ready** (PM lane). Merge lane owns merge after QA Ready. No Status Board from Verify. Fleet env left **UP**; teardown on closeout is PM.

## Verify exit note (gates)

`phase=done`. `BUILD_RECORD_GATE` pass id=41825. `REWORK_GATE` pass (only investigation artifacts since reviewed_sha `3b9bc75…`).

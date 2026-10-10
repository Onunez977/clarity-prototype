<!-- ticket-status -->
**Ticket:** [DEV-2659](https://insightlpr-team.atlassian.net/browse/DEV-2659)
**Phase:** Implement (tip-fix)
**Done:** intake · Ultra-Prep · Question Gate · Ultra-Plan (+ dual-plan compare) · ticket provision (fleet Docker CH cite) · Implement · Review rn=1 · Agent QA FAIL · tip-fix Implement
**Next:** Review
**Open questions:** none
**Sprint:** Sprint 41
**Ship:** Both funnel queries read `clarity.billing_claim_events_v` with `is_internal = 0`. Delete the orgs CTE and the insightlpr needle joins. Shared dedup triple. Item 5 / AC-7 out of ship. No `type IN (1, 2)` docblock: no orgs path remains.
**Provision cite:** site `http://127.0.0.1:18083` `clarity-qa-app-dev-2659` GET `/app/login` → 200. PG `127.0.0.1:15435` `zod_dev_2659` + `zod_testing_dev_2659` user `clarity`. CH strategy=`docker` `clarity-qa-clickhouse-dev-2659` HTTP `:18126` keyed `clarity_dev_2659` (+ `clarity_testing_dev_2659`) + zod twins. `billing_claim_events` / `_v` / `_mv` present. `cloud_touched=false`. `mac_herd_refused=true`. Ledger `docs/investigations/DEV-2659/qa-prep-ledger.json`.
**SHOW CREATE VIEW:** not run from this VM (`127.0.0.1:18126` connection refused). Live body prove waits Agent QA on the fleet host.
**Assignees:** Jira Dalton McCleery (unchanged). GitHub `DaltonMcCleery`.
<!-- /ticket-status -->

# DEV-2659 journal

## Phase 0 — intake (2026-09-24)

Live Jira re-GET. Story, To Do, Medium, Sprint 41 (active), Application Clarity, assignee Dalton McCleery. Assignee not changed. Parent DEV-2409 (In Code Review). Blocked-by DEV-2655 Done and DEV-2656 Done.

Base is `origin/main` `a4b5074630771c1f4121a5ca34f255c71afe0755` (DEV-2654 / #436). `25870f75661f07a27a69124a414e557d4c11fff0` (#449) is an ancestor. Local `main` before fetch was `8d90fd2e`.

Branch `cursor/dev-2659-billing-funnel-plan-0b83`. Stop line: do not mark provisioned. No product PHP.

## Phase 1 — Ultra-Prep (2026-09-24)

Read `okf/index.md`, `okf/concepts/data-architecture.md`, `okf/concepts/billing-events.md`, `okf/concepts/company-metrics-dashboard.md`, `okf/concepts/clickhouse-query-patterns.md`, `okf/concepts/platform-gotchas.md` (PG-028, PG-030). ClickHouse stays read-only. Wiki edits are queued, not made.

Denormalized shape on this base (`database/schema/clickhouse-schema.sql`):

- Line 18: `clarity.billing_claim_events`, `ReplacingMergeTree(_version, _peerdb_is_deleted)`, `ORDER BY (event_date, id)`.
- Line 85: `clarity.billing_claim_events_v` = `FINAL` where `_peerdb_is_deleted = 0`. Projected columns: `id`, `org_id`, `agent_org_id`, `vin`, `hotlist_id`, `event_type`, `success`, `is_internal`, `event_date`, `ts`, `api_key_id`, `_peerdb_synced_at`, `_version`. The view does not project `_peerdb_is_deleted`.
- Line 102: `clarity.billing_claim_events_mv` refreshes hourly. `event_type = 'case-claim'`. `is_internal` is computed from `zod.public_orgs FINAL` over all org types, not from `clarity.orgs`.
- Line 89: `clarity.orgs` is `SELECT * FROM zod.public_orgs WHERE type IN (1, 2)`. No `FINAL`. No tombstone filter. CDC columns are still projected.

Neither funnel class extends `ClickhouseQuery`. `UniqueLprCaseClaimsQuery` orgs CTE is lines 252–256, claims scan is lines 257–271 (`zod.public_billing_events`, `JSONExtract*`, `public_api_keys`), needle joins are lines 272–280. The deduped stage is inline `uniqExactIf` at lines 217–218. `LprRepossessionsQuery` mirrors that preamble (orgs 167–171, claims 172–187, kept 188–196) and materializes `deduped` at lines 197–202, read once by the outer SELECT. Sentinel guards already present: `d.agent_org_id != 0` (line 208), `h.id != 0` (line 220). Pre-claim uses `h.hotlist_id != 0` (`UniqueLprCaseClaimsQuery` line 375).

The orgs CTE is read only by those two needle LEFT JOINs in each class. `is_internal = 0` replaces them. After that deletion the CTE has no reader.

Jul 2026 UTC reconciliation target, `ls-claim` excluded, platform-wide: all 15128 / successful 14288 / deduped 11975. Same figures in `okf/concepts/billing-events.md` and Jira comment 41325.

Closed-month settle already exists on this base: `ResolvesDashboardCacheTtl::CLOSED_MONTH_SETTLE_FULL_SECONDS` (8 days) and `baseDashboardCacheTTL()`. Not part of this key.

No `qa-prep-ledger.json` in the checkout. Soft-OK against another fleet ledger is not claimed.

## Phase 2 — Question Gate (2026-09-24)

First pass blocked on Q1 and did not write a plan. Steering then said: confirm the orgs CTE from live code and the ticket; if it is unused after `is_internal = 0`, plan the deletion, and keep the type IN (1, 2) docblock only if an orgs path remains.

Re-GET of DEV-2659 on 2026-09-24: description and comments 41179, 41325, and 41330 are unchanged. Assignee is still Dalton McCleery.

Code on this base: `UniqueLprCaseClaimsQuery` lines 252–280 and `LprRepossessionsQuery` lines 167–196. The `orgs` CTE is read only by the two needle LEFT JOINs in `kept`. Scope item 2 deletes those joins. The MV at schema line 102 computes `is_internal` from `zod.public_orgs FINAL` over all org types. After the deletion, neither query reads `clarity.orgs`.

Q1 answer: delete the CTE. Do not swap it. Do not add the type IN (1, 2) docblock. Hazard 2 does not apply to this read path. Comment 41325 already records the four insight orgs as types 1, 2, 3, and 4, with zero claims on the two types outside (1, 2). This run did not re-measure Cloud ClickHouse.

Comment 41330 still says "in-query clarity.orgs dedup" is in the ship. That sentence conflicts with item 2, comment 41325, and the code. The steering resolves it: the unused path is deleted. Journaled. Gate **CLEAN**.

Item 5 / AC-7 stays out of ship.

## Phase 3 — Ultra-Plan (2026-09-24)

`plan.json` and `plan.md` are the primary plan. A later draft compare is not this plan.

Read surface is `clarity.billing_claim_events_v` (schema line 85). Subtasks: shared dedup triple, claims query, repos billing leg. Hotlist and status-log legs stay. Neither class extends `ClickhouseQuery`.

Fleet Docker path for Agent QA and Verify is in the plan. This phase does not mark provisioned. Jira comment 41823.

## Drift versus the dispatch

- Comment 41179 still says Verdict Hold and that the billing objects are absent from platform main. This base has them via #449, and DEV-2654 is merged at the tip. Scope and AC text are unchanged. Journal only.
- Comment 41330 names an in-query `clarity.orgs` dedup. Code plus the later steering delete that path. Recorded above. Not left open.
- Parent DEV-2409 is In Code Review. The dispatch did not name the parent status. Not a scope change.

## Phase 4 — Provision (2026-09-25)

Ledger cite only: `docs/investigations/DEV-2659/qa-prep-ledger.json` (status `live`, timestamp `2026-09-24T19:03:54-05:00`). Untracked copy at `.context/qa-prep-ledger.json` (gitignored). `restand-prove.md` matches that cite.

| Surface | Cite |
| --- | --- |
| Site | `http://127.0.0.1:18083` · `clarity-qa-app-dev-2659` · GET `/app/login` → 200 |
| Postgres | `127.0.0.1:15435` · `zod_dev_2659` + `zod_testing_dev_2659` · user `clarity` |
| ClickHouse | strategy=`docker` · `clarity-qa-clickhouse-dev-2659` · HTTP `:18126` · `clarity_dev_2659` + `clarity_testing_dev_2659` + `zod_dev_2659` + `zod_testing_dev_2659` |

Ledger `prove_select` PASS: `billing_claim_events`, `billing_claim_events_v`, `billing_claim_events_mv` on `clarity_dev_2659` (table_count 31). `cloud_touched=false`. `mac_herd_refused=true`. Refused: Mac Herd, `:18124`, `:18125`, Cloud ClickHouse writes.

`SHOW CREATE VIEW clarity_dev_2659.billing_claim_events_v` from this VM: connection refused on `127.0.0.1:18126` (same for `:18083` and `:15435`). No Cloud query. Live body prove waits Agent QA on the fleet host. Committed `database/schema/clickhouse-schema.sql` line 85 is `FROM clarity.billing_claim_events` (literal `clarity.` / `zod.` prefixes also in the MV at line 102). If the keyed view body still uses those literals, Agent QA is soft-blocked until a DDL rewrite is recorded.

Dual-plan files landed beside the primary plan. `plan.md` / `plan.json` stay the authority. Compare adopts for Implement (not a plan rewrite):

- t0: add `clarity.billing_claim_events` to `RequiresClickhouse::CLICKHOUSE_TABLES_TO_TRUNCATE`.
- `UniqueScansToCaseClaimsQueryTest` needle, and no `billing_claim_events_v` in SQL comments.
- MV semantic docblocks: frozen `is_internal`, 8-day api-key window, tombstone / `_peerdb_is_deleted` unexpressible on `_v`.
- `SHOW CREATE VIEW` keyed-prefix prove (open until the fleet host runs it).
- Keep helper name `BillingClaimDedup`. Prefer `event_date` bounds as PHP `toDate('Y-m-d')` literals. Assert both rendered SQLs contain `is_internal = 0`.

Jira comment 41824. Phase `provisioned`. Next: Implement. PR stays draft. No product PHP.

## Phase 5 — Implement (2026-09-25)

`plan.md` / `plan.json` stayed the authority. Compare notes adopted here, not as a plan rewrite.

Wiki cite, not an edit: `okf/concepts/billing-events.md` still names `zod.public_billing_events` as Clarity's only read path (line 21) and the 1-arg `ReplacingMergeTree(_peerdb_version)` tombstone rule (lines 95–105). That page waits for approval. These two queries now read `clarity.billing_claim_events_v`. The CDC mirror's `ORDER BY id` "nothing prunes" note does not describe `billing_claim_events` (`ORDER BY (event_date, id)`, schema line 18).

Subtasks:

- t0: `clarity.billing_claim_events` added to `RequiresClickhouse::CLICKHOUSE_TABLES_TO_TRUNCATE` before the feature seeds.
- t1: `BillingClaimDedup::tripleSql()` is `(forwarder_org_id, agent_org_id, vin)`. `dedupedCteSql()` is the repos CTE (`success`, `vin != ''`, `min(claim_date)`). No constructor. The helper does not emit an aggregate.
- t2: `UniqueLprCaseClaimsQuery` reads the view, aliases `org_id` as `forwarder_org_id`, filters `is_internal = 0`, and keeps `ts BETWEEN` plus PHP `toDate('Y-m-d')` `event_date` bounds (same shape as repos `{floor}`). Window and monthlySeries are one SELECT: `count()`, `countIf(success)`, `uniqExactIf` of the triple. Pre-claim still references `oc` twice and keeps the `hits_cold` join and `h.hotlist_id != 0`.
- t3: `LprRepossessionsQuery` uses the same claims filter and `BillingClaimDedup::dedupedCteSql()`. The outer SELECT reads `deduped` once. `d.agent_org_id != 0`, `h.id != 0`, and both hotlist joins stay. Hotlist legs stay on zod.

Both class docblocks record the MV risk trail and do not change the ship ACs: `is_internal` is frozen at refresh from `zod.public_orgs FINAL` (all org types); API-key changes re-read only an 8-day window (`today() - 8`); `_peerdb_is_deleted` is unexpressible on `_v` because the view does not project it. Neither class reads `clarity.orgs`. No `type IN (1, 2)` paragraph. Neither class extends `ClickhouseQuery`. `{org} = 0` stays platform-wide. Item 5 / AC-7 stays out. Cache TTL unchanged.

`UniqueScansToCaseClaimsQueryTest` counts inlined `billing_claim_events_v` at 1 / 1 / 2 / 2. The view name is not in SQL comments. InsightLPR name tests now seed `is_internal = 1`. Forwarder-side and agent-side are the same flag at this layer; titlemax stays a non-internal row that counts. Sentinels remain: `agent_org_id = 0` and hotlist id `0`.

Tests, `CLICKHOUSE_DRIVER=clickhouse`, local Docker ClickHouse `127.0.0.1:8123` databases `clarity` and `zod` (the VM scratch server, not fleet `:18126`): **119 passed, 431 assertions**. Classes: `BillingClaimDedupTest` (3), `UniqueLprCaseClaimsQueryTest` (15), `LprRepossessionsQueryTest` (21), `UniqueScansToCaseClaimsQueryTest` (16), `CardQuerySqlFidelityTest`, `CompanyMetricsCacheScopeTest` (1). A pgsql mirror was not the done-signal. Fleet `:18126` was not reachable from this VM and was not written. Cloud ClickHouse was not written. `SHOW CREATE VIEW` on the keyed database is still open for Agent QA.

Product commit `ccfbc676`. Phase `implemented`. Next: Review. PR stays draft. Assignee stays `DaltonMcCleery`. No `okf/` edit.

## Phase 6 — Review rn=1 (2026-09-24 CDT)

Adversarial tip-fix Review on product tip `ccfbc676a19137695b3e992f41942fc19b3a91ae`. Branch tip `1d6e2b89a2009700ac7906aee78a14601eed18f9` confirmed docs + docblock-only on BillingClaimDedup / LprRepossessionsQuery (no executable SQL/PHP delta). `reviewed_sha` = **branch tip** so REWORK_GATE does not fail-closed on `[Bb]illing` path matches from the docblock commit. PR #465 stays draft OPEN.

Ship claims (all YES with file:line in `.ai/correctness.md`):

1. Both funnels read `{clarity_db}.billing_claim_events_v` + `is_internal = 0`.
2. `BillingClaimDedup` owns triple + repos `deduped` CTE.
3. orgs CTE / needle / JSONExtract / public_api_keys GONE from these funnels.
4. Item 5 / AC-7 OUT (triple stays three columns).
5. UniqueScansToCaseClaims needle/tests updated (`is_internal`, `_v` counts 1/1/2/2).
6. `RequiresClickhouse` truncate list includes `clarity.billing_claim_events`.

Leftover hunt clean: no `clarity.orgs` CTE, no needle in rendered SQL, no base-table read in queries, `forwarder_org_id` in triple, sentinels kept.

Scores: correctness **94** / conventions **91** / blast-radius **92**. min=91 threshold=85. Gate PASS. Act-on Implement: **NONE**. Next: **Pippin**.

Soft ≤5: (1) Implement tests on VM `:8123` not fleet `:18126`; pgsql not done-signal. (2) Stale `kept` prose in UniqueLprCaseClaimsQuery method docs. (3) okf billing-events wiki still names `zod.public_billing_events` (wait for approval). (4) Repos outer SELECT hardcodes aliased triple columns (CTE still helper-owned). (5) `SHOW CREATE VIEW` on fleet still open for Agent QA.

Blocking FAIL: none. Artifacts under `.ai/` (not committed). Do not `git add` `.ai/` or `.context/`.

## Phase tip-fix — Implement (2026-09-25)

Agent QA FAIL ~19:33 CT — 2 fails on `BillingClaimDedupTest` (`uniq` in the `BillingClaimDedup` docblock; `LprRepossessionsQuery` `clarity.orgs` wrap). Fixes already in `1d6e2b89`; `tip_sha` corrected so Agent QA checks out the fixed tree. Next: Review.

HEAD `7780401d` already contained both docblock fixes from `1d6e2b89a2009700ac7906aee78a14601eed18f9`. `BillingClaimDedup.php` has no `uniq` substring. `LprRepossessionsQuery.php` line 65 and `UniqueLprCaseClaimsQuery.php` line 22 keep the contiguous phrase `does not read \`clarity.orgs\``. No executable SQL or PHP change in this commit. `ccfbc676` still has both mismatches; that was the tree Agent QA checked out.

`state.phase=implemented`. `state.next=Review`. `flags.implement=true`. `flags.review=false` until Review runs again. `flags.stop_at=implemented`. `cloud_touched=false`. No Cloud ClickHouse write.

Re-run under `CLICKHOUSE_DRIVER=clickhouse` on local Docker ClickHouse `127.0.0.1:8123` (databases `clarity` and `zod`; not fleet `:18126`; not Cloud):

- `php artisan test --compact --filter=BillingClaimDedupTest` — 3 passed, 80 assertions.
- Same six classes as Implement (`BillingClaimDedupTest`, `UniqueLprCaseClaimsQueryTest`, `LprRepossessionsQueryTest`, `UniqueScansToCaseClaimsQueryTest`, `CardQuerySqlFidelityTest`, `CompanyMetricsCacheScopeTest`) — 119 passed, 431 assertions.

`cloud_touched=false`.

## Phase 6 — Review rn=2 light tip-fix (2026-09-24 CDT)

Light tip-fix Review after Agent QA FAIL on stale tip `ccfbc676`. Branch tip `3b9bc75aa083137f85a69302483c8c95acb5fbf8`. Diff `1d6e2b89..3b9bc75` is docs-only (journal / state / ticket-status). No executable SQL/PHP delta since rn=1.

QA FAIL root cause proved fixed in `1d6e2b89` (already under rn=1 reviewed_sha): BillingClaimDedup docblock no longer contains `uniq`; LprRepossessionsQuery keeps contiguous `does not read \`clarity.orgs\``. Product view-read tree remains `ccfbc676`.

Scores: correctness **95** / conventions **92** / blast-radius **93**. min=92 threshold=85. Gate PASS. Act-on Implement: **NONE**. `reviewed_sha` = branch tip `3b9bc75`. Next: **Pippin** Agent QA on fleet `:18126` (checkout tip ≥ `1d6e2b89`, not product-only `ccfbc676`).

Soft: fleet `:18126` required; `SHOW CREATE VIEW` open; stale `kept` prose; okf wiki lag. Blocking FAIL: none. Artifacts under `.ai/` (not committed).
## Phase 7 — Verify / Build Record (2026-09-24 ~19:46 CDT)

Tip-fix rn=2 Verify after Agent QA PASS on real fleet Docker ClickHouse.

- Product tip / reviewed_sha: `3b9bc75aa083137f85a69302483c8c95acb5fbf8` (≥ docblock fix `1d6e2b89…`). Refused Verify on `ccfbc676…` alone. PR head `4584b5a…` (docs Review stamp). Ancestry `1d6e2b89` ⊂ `3b9bc75` ⊂ `4584b5a` confirmed.
- Hold vs DEV-2654 lifted (PR #436 merged). Proceed to normal closeout; PR #465 stays **DRAFT**.
- Agent QA cite: Pint PASS 10 · PHPUnit 189/707/0 skip · `CLICKHOUSE_DRIVER=clickhouse` @ `127.0.0.1:18126` · keyed clarity_dev_2659 (31) · `cloud_touched=false` · `:18124`/`:18125` untouched · Live NOT_COVERED soft · SHOW CREATE VIEW open/non-blocking.
- Review rn=2: scores 95/92/93 · min 92 · `rerun_required=false` · act_on NONE. Thin conventions #16: conventions 92; Pint 10; OKF wiki lag soft.
- ACs 1–6 + 8 + 9 (plan) SATISFIED; AC-7 OUT OF SHIP; Preflight #18 SATISFIED.
- Build Record posted: Jira comment **41825** (marker `Insight AI — Build Record`). No bot names.
- Next: **Undraft / CI / QA Ready** (PM). No Status Board / undraft / merge from Verify.
- Fleet env left **UP**.

Gates (2026-09-24 19:47:15 CDT):

```
BUILD_RECORD_GATE: pass id=41825 (route=feature, ac=10, review_rounds=3, rerun_required=false)
REWORK_GATE: pass (only investigation artifacts changed since review — no code rework)
```

`phase=done`. Docs tip after BR commit: `4e4b6e3a14bcd935613c5846197dcb5d97ae4fe4` (`[skip ci]`). PR #465 remains DRAFT.

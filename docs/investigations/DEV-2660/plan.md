# DEV-2660 — Ultra-Plan

**Phase:** plan. **Gate:** CLEAN. **Branch path:** main→main (First Glance **42078**). **Base:** `main` @ `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`. **Provisioned:** false.

This file is the primary plan. The Claude draft compare is not this plan.

Input: `docs/investigations/DEV-2660/prep-context.json`.

## Ship surface

Retire the recoveries-leg `zod.public_hotlist_status_log` LEFT JOIN in `LprRepossessionsQuery`. Read `clarity.hotlist.first_repossessed_at` (MV `minIf(toNullable(created_at), new_status = 3)`). Keep `zod.public_hotlist FINAL` as the (org, vin) match. Product PHP only.

ACs 1–7 are in. Adopt `min()` knowingly (forecast residue **0.187%** vs prior any/max). Dump name `first_declined_at` is status 4 and is not the recoveries column.

No product PHP in this phase. No `okf/` edits. No Cloud ClickHouse writes. Do not mark `phase=provisioned`. Do not invent a fleet DSN.

## What is already true

Tip schema (`database/schema/clickhouse-schema.sql`):

| Object | Line | Fact |
| --- | --- | --- |
| `clarity.hotlist` | 24 | `first_repossessed_at` / `first_declined_at` `Nullable(DateTime64(6))`. Engine `ReplacingMergeTree`. |
| `clarity.hotlist_incremental_mv` | 103 | `hsl_deduped`: `minIf(..., new_status = 3)` → `first_repossessed_at`; status 4 → `first_declined_at`; `_peerdb_is_deleted = 0`, no `FINAL` in that CTE. |

Recoveries leg today (`LprRepossessionsQuery.php`):

- Lines 200–203: `zod.public_hotlist FINAL`, status = 3, match `h.org_id = d.agent_org_id AND h.vin = d.vin`, guard `d.agent_org_id != 0`. VIN un-normalised.
- Lines 211–215: `zod.public_hotlist_status_log`, new_status = 3, `{floor}` pre-filter, guard `h.id != 0`.
- Lines 173–174: `db_repos` = `uniqExactIf(..., toDate(hsl.created_at, 'UTC') >= d.claim_date - 7)`.
- Line 147: `{floor}` = UTC window start minus 7 days (PHP `toDate('Y-m-d')`).
- Docblock 52–57: variant B (`repo_date`) still recorded as measured-and-rejected (23%).
- Class does not extend `ClickhouseQuery` (lines 15–20).

Sentinel fixtures: `LprRepossessionsQueryTest.php` agent org 0 (~187) and `hotlist_id = 0` (~225). `BillingClaimDedupTest.php` 86–90 still pins `public_hotlist_status_log` and floor literal `toDate('2026-06-24')`.

## Decisions

| # | Decision |
| --- | --- |
| Swap | Replace only the status-log LEFT JOIN. Keep `public_hotlist FINAL` (org, vin) match. Do **not** collapse both joins into one `clarity.hotlist` rewrite. |
| Timestamp | `clarity.hotlist.first_repossessed_at`. Predicate: `toDate(ch.first_repossessed_at, 'UTC') >= d.claim_date - 7`. |
| min vs max | Adopt `min()` (MV). Known residue 0.187% vs current any/max. Stop the swap if live Jul 2026 delta exceeds that recorded residue plus AC-1 disagreement. |
| `{floor}` | **KEEP** (with reason). It was never a sort-key prune on `public_hotlist_status_log` (`ORDER BY id`); it shrinks the **hash build side**. That role transfers to the `clarity.hotlist` subquery (`toDate(first_repossessed_at, 'UTC') >= {floor}`). Answer-neutral: `{floor}` = window start − 7 ≤ `min(claim_date) − 7`. The −7 on the `db_repos` predicate stays. Packet `m2c` may still drop `{floor}` if `read_bytes` / `memory_usage` are within noise — default is keep. |
| −7 | Deliberate keep. Not a side effect of dropping the log. |
| New join | `LEFT JOIN (SELECT id, first_repossessed_at FROM {clarity_db}.hotlist …) AS ch ON ch.id = h.id AND h.id != 0`. Carry both sentinels. Do not set `join_use_nulls = 1`. |
| FINAL on clarity.hotlist | **Measure decides** (packet `final-on-clarity-hotlist`). Raw SQL does not inherit Eloquent `ApplyFinal`. Wiki has no measured delta for this column (UNPROVEN). Prefer no `FINAL` if `db_repos` and the summary header do not move; pay `FINAL` only if the answer moves. |
| VIN | Keep `h.vin` un-normalised. |
| Base class | Do not extend `ClickhouseQuery`. |
| Aggregate | Do not touch `hotlist_status_aggregate`. |
| Cache | Do not retune shared keys. Payload stays `{deduped, db_repos}` — no `-v1` bump. |
| Docblock AC-7 | Rewrite A1-only / repo_date rejection so a future reader sees variant B as viable **because** `first_repossessed_at` is the transition timestamp. Do not cite `repo_date` as that timestamp. Keep the cache-scope test's asserted docblock tokens (the hotlist-match key and this ship key) for `CompanyMetricsCacheScopeTest`. |
| Wiki | No `okf/` edits in flight. Gaps stay UNPROVEN (model page omits `first_*_at`, still says `SharedReplacingMergeTree`; billing-events / company-metrics pages do not document this join). |

## Candidate SQL shape (illustrative)

Keep claims + deduped CTEs. Outer:

```sql
FROM deduped AS d
LEFT JOIN (
    SELECT id, org_id, vin FROM {db}.public_hotlist FINAL
    WHERE _peerdb_is_deleted = 0 AND status = 3
) AS h ON h.org_id = d.agent_org_id AND h.vin = d.vin AND d.agent_org_id != 0
LEFT JOIN (
    SELECT id, first_repossessed_at FROM {clarity_db}.hotlist /* FINAL? measure decides */
    WHERE _peerdb_is_deleted = 0
) AS ch ON ch.id = h.id AND h.id != 0
```

`db_repos` predicate uses `ch.first_repossessed_at`. No `public_hotlist_status_log`. Keep `{floor}` on the clarity subquery (default). Optional `IS NOT NULL` on `first_repossessed_at`.

## Preflight #25 — Cloud CH READ-ONLY measure (before swap)

Do **not** skip. Do **not** treat a local seed as this packet. Prior snapshots **9137** (recoveries-leg measure, 2026-09-09) and **9149** (billing-view snapshot, 2026-09-25) are **not** the live baseline. No DSN recorded in this plan. Cloud is READ-ONLY (no INSERT / ALTER / DELETE).

| Packet | Window / shape | Compare | Record |
| --- | --- | --- | --- |
| `ac1-agreement` | Counted-pair population of the recoveries predicate. Also report Jul 2026 if it differs from any April historical window. | Status-log transition (`new_status = 3`, `_peerdb_is_deleted = 0`, no FINAL) vs `clarity.hotlist.first_repossessed_at`. Also min() vs current any/max residue vs 0.187% forecast. | Agreement %, numerator, denominator. Close the historical 23% `repo_date` figure as the closed comparison, not the new-column result. |
| `ac3-jul-2026-db-repos` | 2026-07-01 00:00:00 UTC → 2026-07-31 23:59:59 UTC, `org = 0`. | Current `windowSql` vs candidate. `deduped` and `db_repos`. Set-diff of counted triples if the scalar moves. | Tolerance = AC-1 agreement with known min-vs-max residue called out. Delta outside that residue **stops the swap**. |
| `ac4-summary-header` | Same Jul 2026 platform-wide statement, old then candidate. | `X-ClickHouse-Summary`: `read_rows`, `read_bytes`, `memory_usage`. | Expect a clear drop (status-log scan gone). Wall clock is not the acceptance figure. Context only: q8 A1 was 664,270,998 rows / 15.04 GB / 2.50 GB peak (pre-billing-view). |
| `final-on-clarity-hotlist` (M2b + M6) | Candidate with/without `FINAL`; plus `HAVING count() > 1` on counted `h.id`s. | `db_repos` + summary; whether same-id duplicates exist (`ORDER BY` is not `id`-only). | Keyword from M2b+M6. May be correctness, not only cost. |
| `m2c-floor` | Candidate with `{floor}` removed. | Summary vs kept-floor candidate. | Within noise → may drop `{floor}`; default keep. |
| `m4-min-max-residue` | Triples with ≥2 status-3 rows and `min < claim_date−7 ≤ max`. | Exact min-vs-max residue set. | Compare to 0.187% forecast. Do not infer from AC-1 alone. |
| `m5-mv-backfill` | Counted matched ids where status-3 log exists but `first_repossessed_at IS NULL`. | MV watermark / backfill coverage. | Non-trivial count **stops the swap** (would blow AC-1). |

Execute these packets in Implement (or a measure-only pass) **before** landing the product PHP swap. This Ultra-Plan phase records the plan only.

## Preflight #18 — fleet Docker CH

Agent QA / Verify later use the real fleet Docker ClickHouse (`CLICKHOUSE_DRIVER=clickhouse`). A pgsql mirror is not the done-signal. This plan names **no** host, port, container, or Mac/Herd DSN. Provision writes the ledger for this key before Agent QA. `provisioned` stays **false** here. Do not reuse another key's ledger.

## Subtasks (Implement)

1. **t1 — measure packet (#25).** Cloud READ-ONLY. Run the four packets above. Record figures in `docs/investigations/DEV-2660/` (e.g. `cloud-parity-jul-2026.md`). Stop if AC-1 or AC-3 fail the locked tolerance.

2. **t2 — SQL swap.** Depends on t1 green. Edit `LprRepossessionsQuery.php`: drop status-log join; add `clarity.hotlist` join for `first_repossessed_at` keyed on `h.id` with `h.id != 0` and `{floor}` retained on that subquery; flip `db_repos` predicate; rewrite docblock (AC-7 + record `{floor}` keep reason). Keep `public_hotlist FINAL`, un-normalised VIN, `d.agent_org_id != 0`, class off `ClickhouseQuery`, shared cache keys.

3. **t3 — tests.** Same diff as t2. Update `BillingClaimDedupTest.php`: drop `public_hotlist_status_log` pin (`assertStringNotContainsString`); keep `toDate('2026-06-24')` while `{floor}` stays; assert `first_repossessed_at` / both sentinels / not a subclass. Add a min()-semantics fixture (two status-3 transitions, min below floor, max inside → `db_repos === 0`). Update `LprRepossessionsQueryTest.php`: move the stray-zero fixture onto the new join key (seed a `clarity.hotlist` row whose `id` is the sentinel, or equivalent that fails if `h.id != 0` is removed). Keep agent-org-0 fixture. Feature seeds must insert into the table behind any view (`clarity.hotlist`), not invent columns.

4. **t4 — fidelity / cache scope.** Keep `CompanyMetricsCacheScopeTest` green (the query docblock still contains the hotlist-match key and this ship key that the test asserts). Touch `CardQuerySqlFidelityTest` only if a reflected SQL string breaks.

## Out of scope

- Implementing or measuring Cloud in this Ultra-Plan phase beyond recording the plan.
- Provisioning a fleet ledger / inventing a DSN.
- Collapsing `public_hotlist` + status-log into one `clarity.hotlist` (org, vin) join.
- `hotlist_status_aggregate`, billing-leg reopen, CohortClosed/Declined edits.
- Using `repo_date`, `order_date`, `close_date`, `first_declined_at`, or `first_closed_positive_at` as the recoveries timestamp.
- Normalising `h.vin`. Extending `ClickhouseQuery`. Setting `join_use_nulls = 1`.
- Cache TTL / key retune. `okf/` edits. Cloud writes. chlocal / schema dump / projection work.
- New helper class or abstraction (preflight #24).

## Invariants

- Synchronous failures still propagate (no `ClickhouseQuery::query()`).
- `{org} = 0` stays platform-wide.
- Payload contract of shared keys unchanged.
- Remaining status-log readers outside this class already use `_peerdb_is_deleted = 0` without FINAL — leave them.

## Next

Provision (waits a fleet ledger for this key). Then Implement (measure #25 first, then PHP).

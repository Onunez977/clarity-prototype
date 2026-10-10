# DEV-2660 — Implement plan (independent draft, preflight #23)

Base `76d4f5f` · gate CLEAN @ `f1dff1f` · Cloud CH **READ-ONLY** · no product PHP written in this run.

---

## 1. What actually changes

One subquery and one column reference. `LprRepossessionsQuery::sql()` lines 211–215 (the `hsl` leg) and the `uniqExactIf` predicate at lines 173–174. Everything else in the statement is untouched.

**Current right side** — `zod.public_hotlist_status_log`, `PRIMARY KEY id ORDER BY id` (schema line 66). Note the `{floor}` on `created_at` was **never a sort-key prune** there either: `created_at` is not in the sort key, so `{floor}` has always been a *filter that shrinks the hash build side*, not an I/O prune. That matters for the `{floor}` decision below.

**Candidate right side** — `clarity.hotlist`, one row per hotlist id, with `first_repossessed_at Nullable(DateTime64(6))` fed by `hotlist_incremental_mv`'s `hsl_deduped` CTE: `minIf(toNullable(created_at), new_status = 3)` over `zod.public_hotlist_status_log WHERE _peerdb_is_deleted = 0` (no `FINAL`) — i.e. the **same tombstone discipline the current leg already uses**, aggregated upstream.

```sql
LEFT JOIN (
    SELECT id AS hotlist_id, first_repossessed_at
    FROM {clarity_db}.hotlist
    WHERE _peerdb_is_deleted = 0
      AND first_repossessed_at IS NOT NULL
      AND toDate(first_repossessed_at, 'UTC') >= {floor}
) AS r ON r.hotlist_id = h.id AND h.id != 0
```

predicate becomes `toDate(r.first_repossessed_at, 'UTC') >= d.claim_date - 7`.

`join_use_nulls = 0` is safe on the new key both ways: a `Nullable` column on an unmatched row is `NULL`, `NULL >= x` is `NULL`, `uniqExactIf` does not count it. The `IS NOT NULL` filter makes that explicit rather than relying on it, and the `h.id != 0` sentinel carries over verbatim (PG-030).

**Semantic delta, adopted knowingly:** today's `uniqExactIf` over the fan-out is *any/max* semantics — any status-3 row satisfying `>= claim_date - 7` counts. `first_repossessed_at` is `min`. A hotlist with two status-3 transitions (an early one below the floor, a later one inside it) counts today and stops counting after the swap. That is the 0.187% forecast in comment 42078, and it is **directionally downward only**. It is the one behaviour change that needs its own test pin so nobody later "fixes" it back.

---

## 2. `{floor}` — **KEEP**

Written reason: the ticket rule is "remove if dead after the log scan is gone." It is **not dead**. Its job never was pruning the log by sort key (`ORDER BY id`); its job is reducing the **hash build side**, and the candidate right side is exactly the side ClickHouse builds the hash table from. `clarity.hotlist` is the smaller table, but restricting it to repossessed-and-recent hotlists is still the difference between a build side of "every hotlist ever" and "hotlists with a status-3 transition in the window ± 7 days."

The no-change-in-answer argument transfers unaltered: `{floor}` = window start − 7 ≤ `min(claim_date) − 7` for every claim in the window, and the real predicate (`>= d.claim_date - 7`) is strictly tighter. Under `min()` semantics it is *even more* strictly looser, since a `min` below the floor could never have satisfied the predicate anyway.

It also keeps two existing pins alive for free: `BillingClaimDedupTest:90` (`toDate('2026-06-24')`) and `LprRepossessionsQueryTest::test_a_repossession_up_to_seven_days_before_the_window_start_still_counts`.

**What would flip this:** packet M2c below runs the candidate with and without `{floor}`. If `read_bytes` and `memory_usage` are within noise, drop it — the `−7` on the `db_repos` predicate stays either way and the 7-day-reach-back test still passes, because that reach-back lives in the predicate, not the floor. Default is keep; do not drop it without that measurement.

---

## 3. Preflight #25 — measure packet (Cloud CH, READ-ONLY, **before** any product swap)

All statements `SELECT`-only. Record `X-ClickHouse-Summary` (`read_rows`, `read_bytes`, `memory_usage`) for every run — not wall clock (`clickhouse-query-patterns.md:101`). Interleave old/new runs, do not batch them sequentially.

| id | statement | what it answers |
| --- | --- | --- |
| **M1** | `LprRepossessionsQuery::windowSql(0, 2026-07-01T00:00:00Z, 2026-07-31T23:59:59Z)` verbatim | live `deduped` / `db_repos` baseline. **9137 and 9149 are not the baseline** — they predate the current billing view / current data. |
| **M2a** | candidate, no `FINAL` on `clarity.hotlist`, `{floor}` kept | AC-3 |
| **M2b** | candidate, `clarity.hotlist FINAL`, `{floor}` kept | AC-4 FINAL decision |
| **M2c** | candidate, no `FINAL`, `{floor}` removed | the `{floor}` decision above |
| **M3** | over the counted-pair population: `countIf(log-transition satisfies predicate) / countIf(first_repossessed_at satisfies predicate)` + the set-diff | AC-1 agreement %, numerator + denominator |
| **M4** | triples where `countIf(new_status=3) >= 2` **and** `min(created_at) < claim_date − 7 <= max(created_at)` | the **exact** min-vs-max residue set. Compare to the 0.187% forecast. This is a cheap closed-form count — do not infer it from the M3 delta. |
| **M5** | among the counted pairs' matched `h.id`s: rows where a status-3 log row exists but `clarity.hotlist.first_repossessed_at IS NULL` | **MV backfill coverage.** Not in `prep-context`. `hotlist_incremental_mv` only rewrites hotlists inside its watermarks; a hotlist whose last sync predates the column's introduction can carry `NULL` forever. This is the failure mode that would blow AC-1, and M3 alone would report it as generic "disagreement" without naming the cause. |
| **M6** | `SELECT id, count() FROM clarity.hotlist WHERE id IN (<counted h.ids>) GROUP BY id HAVING count() > 1` | whether `FINAL` is a **correctness** question, not just a cost one — see below. |

**Expectations to state before running** (so a surprise is visible as a surprise):

- `read_rows` / `read_bytes` should **drop** — one row per hotlist instead of one per transition, and a column-store read of 3 columns.
- `memory_usage` should drop — smaller hash build side.
- If bytes go **up**, AC-4 fails and the swap is reconsidered, not shipped.

**On `FINAL` (M2b + M6).** `clarity.hotlist` is `ReplacingMergeTree(_version, _peerdb_is_deleted)` with `ORDER BY (toYYYYMM(created_at), org_id, created_at, id)` — the dedup key is **not** `id`. `FINAL` therefore cannot collapse two rows for the same hotlist whose `created_at` or `org_id` differs across CDC versions; it will not fix that class of duplicate. Conversely, if M6 shows duplicate ids, the LEFT JOIN emits >1 row per hotlist and `uniqExactIf` gives *any-qualifying-row* semantics — which is closer to today's behaviour and would *shrink* the M4 residue. Decide the keyword from M2b + M6 together: if M6 is clean and M2a == M2b, drop `FINAL` (the `clarity.hotlist` read costs then follow the `scans_cold`/`uniqExact(ocr)` precedent at `clickhouse-query-patterns.md:90`). The wiki has no measured row for this column — mark **UNPROVEN** until M2b/M6 land, and queue the wiki row for the post-approval pass.

**Stop condition:** an M2a-vs-M1 delta larger than the residue M4 measures, or a non-trivial M5 count, stops the swap. Neither is a tolerance to be negotiated at review time.

---

## 4. Preflight #18 — note only

Agent QA and Verify need the **real fleet Docker ClickHouse**. `provisioned = false`. No host, port, or DSN is recorded here or invented later; the local schema already carries `clarity.hotlist` (dump line 24) and `RequiresClickhouse` already truncates it, so no test-infra work is implied.

---

## 5. Preflight #24 — smallest change

No helper class, no config key, no new abstraction, no shared CTE extraction. One subquery replaced, one column name in two `uniqExactIf` copies (bucketed **and** unbucketed — both are written out at lines 173–174 and only the unbucketed one used to have coverage), one docblock section, the test pins. `{db}`/`{clarity_db}` resolution already exists; the new read uses `{clarity_db}` and `zodDb()` stays because `public_hotlist` stays.

---

## 6. Implement steps

1. **`app/Clickhouse/Queries/LprRepossessionsQuery.php`**
   - Replace the `hsl` LEFT JOIN (211–215) with the `r` join above. Keep the `public_hotlist FINAL` join (200–203) byte-identical.
   - Update **both** `$select` branches (173–174) to `toDate(r.first_repossessed_at, 'UTC') >= d.claim_date - 7`.
   - `{floor}` substitution at line 147 stays (per §2); if M2c overturns that, delete the substitution *and* the `BillingClaimDedupTest:90` pin together.
   - Move the PG-030 sentinel comment block (204–210) onto the new join, rewritten for the new key. Keep the reproduced-on-CH-25.8 note — it is still the reason the guard exists.
2. **Docblock, AC-7.** Replace lines 52–57. Variant B is viable **not** because `repo_date` improved — it did not; `repo_date` is `hotlist_meta`-sourced and the 23% disagreement stands — but because `clarity.hotlist.first_repossessed_at` materialises the **status-3 transition timestamp itself**, via the MV's `minIf(..., new_status = 3)`. State: the `min()` adoption and the measured residue (M4 figure, not the forecast); the `{floor}` verdict and its reason; the `FINAL` verdict with the M2b numbers. Keep the `ClickhouseQuery`-non-extension paragraph and the cache-scope test's asserted docblock tokens (billing leg, hotlist match, and this ship key) (`CompanyMetricsCacheScopeTest:29-31`). Update the `zodDb()` docblock (298) — status-log is no longer read from there.
3. **`tests/Unit/Clickhouse/BillingClaimDedupTest.php`** (lines 84–91)
   - `assertStringNotContainsString('public_hotlist_status_log', $repos)` — this is the AC-2 pin. Note `assertStringContainsString('public_hotlist', $repos)` at line 88 still passes on the kept join; without the `Not` assertion AC-2 is unpinned.
   - Add `assertStringContainsString('first_repossessed_at', $repos)`.
   - Keep `d.agent_org_id != 0`, `h.id != 0`, and `toDate('2026-06-24')`.
4. **`tests/Feature/Clickhouse/LprRepossessionsQueryTest.php`**
   - Add `seedClarityHotlist(int $id, ?Carbon $firstRepossessedAt)` writing `clarity.hotlist`. Every fixture that currently produces a `db_repos` hit via `seedStatusLog(..., newStatus: 3, ...)` gets the paired `clarity.hotlist` row at the same id and timestamp. `seedStatusLog` can stay for the rows the query no longer reads, or go — prefer removing it once nothing reads it, so a future reader does not think the log is still load-bearing.
   - **Sentinel fixture (line 225)** moves to the new key: seed `clarity.hotlist` with `id = 0` and a non-null in-window `first_repossessed_at`; assertion stays `db_repos === 0`. Its sibling at line 251 keeps the same shape. This is the fixture-at-the-sentinel requirement in PG-030 and AC-6.
   - **New test, the one genuine behaviour change:** two status-3 transitions for one hotlist, `min` before `claim_date − 7` and `max` inside it → `db_repos === 0`, with a docblock naming the adopted `min()` semantics and the M4 residue. Without it the residue looks like a bug to the next reader.
   - `test_status3_hotlist_with_no_new_status3_transition_never_counts` becomes "a `clarity.hotlist` row with `first_repossessed_at IS NULL`" — same assertion.
   - `test_soft_deleted_and_duplicate_version_hotlist_rows_do_not_inflate_db_repos` still exercises `public_hotlist FINAL` and needs no semantic change, only the paired `clarity.hotlist` row.
5. `composer format` (Pint). Do **not** run the suite unless asked; CI runs it.
6. Wiki edits stay **queued** — the `clarity.hotlist` / `first_repossessed_at` column row, the FINAL measure row, and the `models/clickhouse/hotlist.md` `SharedReplacingMergeTree` → `ReplacingMergeTree` correction land in one post-approval pass with `php scripts/okf-lint.php`.

---

## 7. Out of scope / non-goals

Collapsing both joins into a single `clarity.hotlist` read · normalising `h.vin` · extending `ClickhouseQuery` · `hotlist_status_aggregate` · `CohortClosedQuery` / `CohortDeclinedQuery` (already no-`FINAL` + tombstone-filtered; they stay out of the diff) · reopening the billing-leg change · `repo_date` / `order_date` / `close_date` / `first_declined_at` as the recoveries timestamp · a column named `first_closed_positive_at` · chlocal, schema-dump, or projection work · any Cloud CH write · `join_use_nulls = 1` · `okf/` edits in flight · provisioning a fleet DSN.

---

## 8. Disagreements / additions vs `prep-context.json` `locked_shape`

No conflict with `locked_shape` itself. Three refinements to the surrounding prep text:

1. **`recoveries_leg_trace.floor.after_swap`** says *"Once the status-log scan is gone, `{floor}` has no log to pre-filter."* That framing points at deadness. It is inaccurate in a way that would produce the wrong call: `{floor}` never pruned the log by sort key (`public_hotlist_status_log` is `ORDER BY id`), and it acquires an equivalent build-side-reduction role on `clarity.hotlist`. §2 keeps it, and names the measurement that could still remove it.
2. **`measure_outline.packets.final-on-clarity-hotlist`** calls `FINAL` "not a product question" — cost only. It may be a **correctness** question: `clarity.hotlist`'s dedup key is `(toYYYYMM(created_at), org_id, created_at, id)`, not `id`, so `FINAL` cannot collapse same-id rows whose `created_at`/`org_id` moved. M6 is added to settle that before M2b is interpreted.
3. **Missing packet: MV backfill coverage (M5).** `prep-context` treats `first_repossessed_at` as uniformly populated because the MV computes it. `hotlist_incremental_mv` only rewrites hotlists inside its watermarks, so historical rows can carry `NULL` indefinitely. AC-1 cannot be trusted without M5, and M3 alone would mis-attribute the cause.

Marked **UNPROVEN** and carried forward: whether `FINAL` on `clarity.hotlist` changes a `first_repossessed_at` read (no wiki row for this column); `okf/models/clickhouse/hotlist.md` still stops its column table at `close_date` and still says `SharedReplacingMergeTree` — the tip dump wins, and both are queued corrections, not inputs.

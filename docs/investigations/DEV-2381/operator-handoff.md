# DEV-2381 — chlocal operator handoff

**This is the work.** Platform `/ticket` on `insight-lpr/platform` cannot apply it.

Precedent: DEV-2177 — **chlocal owns** `clarity.sc_positions` DDL and ingest. Platform is SELECT-only. Design locked by Jeffrey Cobb on [DEV-2342 comment 38238](https://insightlpr-team.atlassian.net/browse/DEV-2342?focusedCommentId=38238).

Do **not** put this ALTER (or MATERIALIZE) in a platform migration, PHP class, or deploy hook. Do **not** uncomment `scout:record-check-ins` (that is DEV-2342 W4, after this lands and a manual cycle is proven).

---

## Exact DDL (locked)

Confirm Cloud columns first (`SHOW CREATE TABLE clarity.sc_positions`). Prod has `id`, `gl`, `timestamp_local`, `ingested_at`. Platform `database/schema/clickhouse-schema.sql` is still a **column-stale** local subset (no `id`/`gl`/`timestamp_local`/`ingested_at`) and is **not** the CREATE to ALTER. The CI dump **does** include `proj_kit_mapping_lc` so Docker PHPUnit sees the projection before Cloud apply; do not copy that CREATE onto Cloud. `SELECT *` is acceptable — the table is narrow.

```sql
ALTER TABLE clarity.sc_positions
ADD PROJECTION proj_kit_mapping_lc
(
    SELECT *
    ORDER BY (lower(kit_mapping), timestamp_utc)
);
```

New inserts start populating the projection. Existing parts stay empty until materialize.

Then, **off-peak**:

```sql
ALTER TABLE clarity.sc_positions
MATERIALIZE PROJECTION proj_kit_mapping_lc;
```

- Budget **~6.5 GiB** extra (second physical copy of ~268–271M rows, TTL 120d).
- Watch `system.mutations` and part counts. The mutation must **finish** before calling this done.
- Do **not** cancel mid-mutation casually.
- Avoid the recorder’s historical ~40k q/h peak if possible. The schedule is currently commented (`routes/console.php`); still keep this off a busy ingest window.

---

## Success EXPLAIN

Replay a **single-kit 15-minute** matcher predicate (shapes + aggregates only; do not log kit mappings, plates, or user identifiers).

```sql
EXPLAIN indexes = 1
SELECT timestamp_utc, geo
FROM clarity.sc_positions
WHERE lower(kit_mapping) = {mapping:String}
  AND timestamp_utc BETWEEN {start:DateTime} AND {end:DateTime};
```

| Plan | Granules (measured 2026-08-13) | Verdict |
| --- | --- | --- |
| `lower(kit_mapping) = ?` **today** (no projection) | **3355 / 33096** | FAIL — PK bypass |
| exact-case `kit_mapping = ?` | **20 / 33096** | prune baseline |
| `lower(kit_mapping) = ?` **after MATERIALIZE** | **tens, not ~3355** | PASS — projection used |

Pass: granule count within a small factor of the exact-case plan (**tens**, not thousands). The EXPLAIN projection section should name `proj_kit_mapping_lc`.

Full query text, `query_log` comparison, and what to do if the optimizer ignores the projection: [explain-runbook.md](explain-runbook.md).

---

## CI dump (ahead of Cloud; not a substitute)

`database/schema/clickhouse-schema.sql` `clarity.sc_positions` is hand-edited so GitHub Actions Docker ClickHouse (26.2) creates `proj_kit_mapping_lc` before you run the Cloud ALTER. `ScPositionsLowercaseProjectionTest` pins that. Workflow YAML is unchanged — both `lint_check_and_test.yml` and `deploy.yml` already `clickhouse-client --multiquery` that file.

ClickHouse 24.8+ `ReplacingMergeTree` + a projection requires `deduplicate_merge_projection_mode`. The CI dump uses `'drop'`, matching `zod.public_hotlist_status_log` in the same file. **Do not copy that setting onto Cloud** without checking Cloud version/settings; the locked ALTER is still ADD PROJECTION + MATERIALIZE only.

## After Cloud apply — platform follow-through (not this run)

1. Re-export `database/schema/clickhouse-schema.sql` via `.claude/commands/chlocal.md` (sibling `../chlocal` cloud exports). That re-export **replaces** this hand edit and should still contain `proj_kit_mapping_lc` plus the real Cloud columns.
2. Update wiki pages listed in [wiki-follow-through.md](wiki-follow-through.md). Record: `lower(kit_mapping)` stays correct; prune comes from `proj_kit_mapping_lc`.
3. DEV-2342 **W4**: operator manual `php artisan scout:record-check-ins` on prod. Do **not** uncomment the schedule until a full cycle is under the 5-minute cadence and `system.mem.pct_usable` stays flat.

---

## Rejected (do not implement)

- ALIAS `kit_mapping_lc`
- Virtual / MATERIALIZED column without changing ORDER BY
- Table rebuild `ORDER BY (lower(kit_mapping), timestamp_utc)`
- Bloom skip index as the **shipped** fix (canary only)
- PHP rewrite off of `lower(kit_mapping)`
- GPS decimation

# DEV-2426 — implementation plan

Source of truth for scope: `docs/investigations/DEV-2426/prep-context.json` (gate CLEAN) plus the
three Phase 2 answers in `journal.md` (two rows per `is_staged` side · attribution frozen at first
sighting · AC3's "after" figure is a post-merge follow-up). **The design is decided — this plan does
not re-open it.**

## The one-line change

The ClickHouse election already keys on the place (`ScoutCheckInMatchQuery.php:211-212` partitions by
`addr_lng, addr_lat, hour_bucket, out_is_staged`). Postgres still keys on the *winner*
(`unique(kit_id, address_id, hour_bucket)`). Thread the book coordinate the query already computes
(`:171-172`) but drops (`:218-226`) out to PHP, store it, and move the unique index onto the
election's own grain.

## DAG

| id | title | depends_on | ‖ | complexity | blast_radius | test_filter |
|---|---|---|---|---|---|---|
| t1 | Project the elected book address geo out of `ScoutCheckInMatchQuery` | — | yes | complex | — | `ScoutCheckInMatchQueryParityTest` |
| t2 | New migration: add `addr_lng`/`addr_lat`, move the unique key | — | yes | complex | **migration**, prod table | `null` |
| t3 | Persist the geo: recorder insert row + `ScoutCheckIn` model | t1, t2 | no | complex | — | `ScoutRecordCheckInsCommandTest` |
| t4 | AC2: all three flip mechanisms, two-run mutated-book tests | t3 | no | complex | — | `ScoutRecordCheckInsCommandTest` |
| t5 | Postgres-layer pins: precision guard, geo provenance, AC4, constraint directness | t4 | no | complex | — | `ScoutRecordCheckInsCommandTest` |

`t1 ‖ t2` — disjoint files. `t4` and `t5` share
`tests/Feature/Console/Commands/ScoutRecordCheckInsCommandTest.php`, so they are serialized rather
than padded into a false parallel.

## Migration DDL — needs an explicit human approval line before Phase 4

New file `database/migrations/2026_08_25_000000_add_address_geo_to_scout_check_ins_table.php`.
**Never edit the shipped `2026_08_10_170314_create_scout_check_ins_table.php`.**

```php
public function up(): void
{
    Schema::connection('platform')->table('scout_check_ins', function (Blueprint $table) {
        $table->double('addr_lng')->nullable()->after('lng');
        $table->double('addr_lat')->nullable()->after('addr_lng');
    });

    $schema = Schema::connection('platform');

    $schema->table('scout_check_ins', function (Blueprint $table) use ($schema) {
        // Guarded so up() still runs after a down() that did not restore it.
        if ($schema->hasIndex('scout_check_ins', 'scout_check_ins_kit_id_address_id_hour_bucket_unique')) {
            $table->dropUnique('scout_check_ins_kit_id_address_id_hour_bucket_unique');
        }
        $table->unique(
            ['kit_id', 'org_id', 'addr_lng', 'addr_lat', 'hour_bucket', 'is_staged'],
            'scout_check_ins_geo_hour_side_unique',
        );
    });
}

public function down(): void
{
    $schema = Schema::connection('platform');

    $schema->table('scout_check_ins', function (Blueprint $table) use ($schema) {
        // Guarded both ways so a partially-applied up() can still be undone.
        // Deliberately does NOT restore the old unique index (Dalton, 2026-08-25).
        if ($schema->hasIndex('scout_check_ins', 'scout_check_ins_geo_hour_side_unique')) {
            $table->dropUnique('scout_check_ins_geo_hour_side_unique');
        }

        $existing = array_values(array_filter(
            ['addr_lat', 'addr_lng'],
            fn (string $column): bool => $schema->hasColumn('scout_check_ins', $column),
        ));

        if ($existing !== []) {
            $table->dropColumn($existing);
        }
    });
}
```

The exact SQL to hand-apply on production, in this order (see
`ac3-measurement-procedure.md` for where it sits in the deploy sequence):

```sql
ALTER TABLE platform.scout_check_ins
  ADD COLUMN addr_lng double precision NULL,
  ADD COLUMN addr_lat double precision NULL;

-- The old constraint is a UNIQUE CONSTRAINT, not a bare index, so it is dropped
-- with ALTER TABLE ... DROP CONSTRAINT. IF EXISTS keeps the script re-runnable.
ALTER TABLE platform.scout_check_ins
  DROP CONSTRAINT IF EXISTS scout_check_ins_kit_id_address_id_hour_bucket_unique;

-- PRE-FLIGHT: must return zero rows. If it does not, the ADD CONSTRAINT below
-- will fail and leave the table with the old constraint already dropped and the
-- new one absent — i.e. no uniqueness guard at all. Resolve the duplicates first.
-- (Reachable if the recorder ran between the ADD COLUMN and the ADD CONSTRAINT:
-- the three statements are not transactional across each other.)
SELECT kit_id, org_id, addr_lng, addr_lat, hour_bucket, is_staged, count(*)
FROM platform.scout_check_ins
WHERE addr_lng IS NOT NULL AND addr_lat IS NOT NULL
GROUP BY kit_id, org_id, addr_lng, addr_lat, hour_bucket, is_staged
HAVING count(*) > 1;

ALTER TABLE platform.scout_check_ins
  ADD CONSTRAINT scout_check_ins_geo_hour_side_unique
  UNIQUE (kit_id, org_id, addr_lng, addr_lat, hour_bucket, is_staged);

ALTER TABLE platform.scout_check_ins OWNER TO graphqluser;

-- REQUIRED: record the migration as run, or the next `artisan migrate` re-runs it
-- and fails on the already-existing columns. Use the current batch number.
INSERT INTO platform.migrations (migration, batch)
SELECT '2026_08_25_000000_add_address_geo_to_scout_check_ins_table', COALESCE(MAX(batch), 0) + 1
FROM platform.migrations
WHERE NOT EXISTS (
  SELECT 1 FROM platform.migrations
  WHERE migration = '2026_08_25_000000_add_address_geo_to_scout_check_ins_table'
);
```

Do not run the recorder between these statements. `up()` guards both the column adds and the index
drop with `hasColumn`/`hasIndex`, so a partial hand-apply no longer wedges the next
`artisan migrate` — verified by putting a database in exactly that state (columns present,
`migrations` row absent) and re-running `migrate` successfully.

**Mind the window between the DDL and the code deploy.** Once the DDL lands, the old unique index is
gone and the new one keys on `addr_lng`/`addr_lat`, which the still-running OLD code never populates.
Postgres treats those NULLs as distinct, so during that window the recorder has **no uniqueness
guard at all** and every overlapping re-match writes a row. Keep the window short, or — since the
schedule is currently commented out — simply confirm no manual run happens between the two steps.

Six things about that DDL are load-bearing:

1. **The old unique index MUST be dropped.** `is_staged` is absent from it, so the corrected
   Direct-side row for a bucket-migrated address collides on `(kit, address_id, hour)` and
   `insertOrIgnore` silently drops it — that *is* the ticket's "keeps `is_staged = true` forever".
   A plan that demotes it to a plain index instead of dropping it is wrong.
2. **`org_id` is in the key, and its absence was a real defect.** `ScoutRecordCheckIns` groups
   drivers by the **driver's** org and stamps that id (`->groupBy('org_id')`), not `$kit->org_id`.
   **It is NOT two orgs in one cycle** — `kit_settings` has `kit_id` as its PRIMARY KEY, so a kit has
   exactly one assigned driver and therefore one org per cycle, and the recorder never calls
   `Kit::getSharedKits()` (that is a visibility helper). The reachable path is **SEQUENTIAL**: the
   kit is reassigned to another org's driver inside the same hour, so a later cycle stamps a
   different `org_id` for the same `(kit, geo, hour)`. The OLD key separated those two rows only
   *incidentally*, through each org's own `address_id`; a geo-only key collapses them and
   `insertOrIgnore` silently discards the second org's check-in — that org's address then reads as
   never visited. Raised as HIGH in review round 1 (its stated mechanism was wrong, the defect real)
   and pinned by
   `a_kit_reassigned_between_orgs_within_an_hour_records_one_check_in_per_org`.
3. **Explicit index name is forced, not stylistic.** Laravel's implicit name
   `scout_check_ins_kit_id_addr_lng_addr_lat_hour_bucket_is_staged_unique` is 69 characters and
   Postgres silently truncates at 63.
4. **Nullable + no backfill is mechanically required.** `NULLS DISTINCT` (the Postgres default)
   exempts every legacy row. A backfill would fail the index build outright, because the duplicate
   rows this bug already wrote are exactly what the new constraint rejects.
5. **`index(['org_id','address_id'])` is untouched** — DEV-1756's derived
   `COUNT(*) WHERE address_id = ?` depends on it.
6. **Lock is a non-issue.** The table is order 10^3 rows and the write path is quiescent (schedule
   commented out at `routes/console.php:59-66`), so the unique-index build is milliseconds and
   `CONCURRENTLY` is unnecessary. Prod has no automated migration path: hand-apply the DDL
   **before** the code deploy.

`down()` deliberately does NOT restore `unique(kit_id, address_id, hour_bucket)` — decided by Dalton
2026-08-25. Restoring it would ERROR on valid data: a mechanism-3 pair is two rows sharing
`(kit_id, address_id, hour_bucket)` with opposite `is_staged`, which
`mechanism_3_an_address_moving_sides_between_rebuilds_preserves_the_frozen_staged_row_and_adds_a_direct_one`
pins as CORRECT. The accepted trade is that a rollback leaves the table with no uniqueness guard, so
pre-change code rolled back alongside it would accumulate true duplicates. **Do not add a restore to
any rollback runbook.**

## The three grep-backstop files

- `tests/Feature/Console/ScoutRecordCheckInsScheduleTest.php` — **no change**, verify green. No
  org/kit/book seeded, so the org loop never runs; it asserts the completed-log `lookback_minutes`
  and exercises the ClickHouse-unreachable path against the new insert shape.
- `tests/Feature/Console/ScoutRecordCheckInsOverrunSignalTest.php` — **no change**, verify green.
  Deliberately ClickHouse-free; never reaches the insert path.
- `database/seeders/ClarityScoutClickhouseSeeder.php` — **no change**, verify only. Writes ClickHouse
  fixtures only and merely lists `scout_open_addresses` in its `REPLICA IDENTITY FULL` loop (`:69`);
  it never inserts book rows and never touches `platform.scout_check_ins`.

## Test obligations (AC2 is the sharp part)

Mechanism 3 is **temporal, not simultaneous** — 0 of 4,519,947 book `address_id`s carry both
`is_staged` values, so the fixture is a DELETE of the winner's Staging book row plus an INSERT of the
same `address_id` on a Direct hotlist at the same geo, across two runs. Its assertion must be the
full four-part combination (total `=== 2`; exactly one `is_staged = false`; exactly one
`is_staged = true`; the `is_staged = true` row's `matched_at` unchanged from run 1). A count-only or
new-row-label-only check passes the actual failure mode (two Staging rows, no Direct row).

The Postgres precision guard asserts **behaviour** — two ~1 m-apart places stay two rows, one place
re-run stays one row — not bit-equality of the stored float. PDO's `precision=14` stringification is
deterministic (so under-collapse is impossible) but not bit-exact, and over-collapse only bites at
~1e-12 degrees, seven orders of magnitude finer than the pinned grain.

Conventions: `ScoutRecordCheckInsCommandTest` uses `#[Test]` + `RequiresClickhouse`;
`ScoutCheckInMatchQueryParityTest` uses `public function test_*()` with `#[Group(...)]` and must seed
through its own `clarityTable()` helper, never the hardcoded `clarity` literal. Control the clock and
anchor fixtures near `now()`. Production is PHP **8.3**. `vendor/bin/pint --dirty --format agent`
before finishing.

## Sizing

**6 files** against the 6-file gate — at the limit, not over:

```
app/Clickhouse/Queries/ScoutCheckInMatchQuery.php
app/Console/Commands/ScoutRecordCheckIns.php
app/Models/ScoutCheckIn.php
database/migrations/2026_08_25_000000_add_address_geo_to_scout_check_ins_table.php
tests/Feature/Console/Commands/ScoutRecordCheckInsCommandTest.php
tests/Unit/Clickhouse/Queries/ScoutCheckInMatchQueryParityTest.php
```

No split recommended: all six sit on the single seam (CH election grain → PG storage grain), three
are one-to-ten-line edits, and the two test files are the AC2/AC4 evidence for that same seam.

`uat_needed: false` — no UI consumer of check-ins exists yet; the recorder is backend-only and the
schedule is disabled. AC3's before/after operator measurement is post-merge (Q3).

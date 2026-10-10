# DEV-2426 — why the address geo is the uniqueness key

Consolidated design rationale, captured at Dalton's request so it outlives the PR thread and
the Jira comment. Mirrored verbatim in the PR body and in the ticket's run-status comment.

## Why the address geo is the uniqueness key

**A check-in identifies a *place*, and the data has no place identifier.** The book gives you
`org_id · is_staged · hotlist_id · address_id · geo`. Nothing in there says "building X." The only
value shared by two address records sitting at one building is the **coordinate**.

**`address_id` cannot work — that is the bug.** One place routinely carries several address records
(duplicate book rows, or genuinely distinct addresses at the same coordinate), each with its own id.
Keying on `address_id` means N rows for one visit, plus another every time the elected winner changes.

**This makes storage agree with an election that was already geo-based.** DEV-2414 already had the
matcher elect winners with `ROW_NUMBER() OVER (PARTITION BY addr_lng, addr_lat, hour_bucket,
out_is_staged)` — the geo was *already* the de-facto place identity, and the query already computed
`addr_lng`/`addr_lat`. It simply dropped them in the final `SELECT`, so storage never saw them. The
two ends had drifted apart and that gap **was** the defect. This is not a new key; it is the
election's own grain, persisted.

**Why not the `lat`/`lng` already on the row.** Those are the GPS **fix** coordinates —
`argMin((lng, lat), timestamp_utc)`, the earliest qualifying fix. The recorder runs every 5 minutes
over a 15-minute lookback, so as the window slides the earliest fix *still inside it* moves later:
`lat`/`lng` **change between runs for the same hour**. Keying on them would write a fresh row every
cycle — strictly worse than the bug. Address geo is stable across runs; fix geo is not. That is why
the row now carries two coordinate pairs.

**Why raw `double precision` and not a geohash or a rounded key.** A coarser key deliberately
collapses coordinates ClickHouse treats as distinct, so Postgres and ClickHouse would disagree about
what "one place" means and real check-ins would be lost at the boundary. Raw doubles preserve exactly
the grain the election uses. Note the soundness argument is **determinism, not bit-exactness** — PHP
truncates to 14 significant digits when binding, but that truncation is a pure function of the input,
so the same address always stores the same value. That is all a unique index requires.

**`org_id` is in the key** because the recorder stamps the *driver's* org, not the kit's, and a kit
reassigned between orgs inside an hour would otherwise have the second org's check-in silently
dropped. (Not two orgs in one cycle — `kit_settings` keys on `kit_id`, so that is impossible.)

**The honest limitation.** The geo is a *derived* identity, not a real one: re-geocode an address and
it moves, which is a new key and a second row — the fourth residual noted below. Closing it needs a
stable place identifier upstream, which the book does not carry. Both the old and new keys are
derived; the geo is derived from something far more stable. Order churn, which drove the old key's
failures, runs to six figures a day; address re-geocoding is a small fraction of that.

## Where each piece is enforced

| Claim | Enforced / evidenced by |
|---|---|
| Storage matches the election's grain | `ScoutCheckInMatchQuery` `ranked` CTE `PARTITION BY addr_lng, addr_lat, hour_bucket, out_is_staged` vs the `scout_check_ins_geo_hour_side_unique` column list |
| A mid-hour winner change is absorbed | `mechanism_1` / `mechanism_2` / `mechanism_3` tests, each with a query-layer positive control |
| N co-located addresses still give one row per side | `several_co_located_addresses_per_side_still_collapse_to_one_row_per_side` |
| Address geo, not fix geo, is stored | `persisted_rows_pin_addr_lat_lng_to_the_book_address_and_lat_lng_to_the_fix` and its query-layer twin |
| ~1 m-apart places stay distinct | `coordinates_roughly_a_meter_apart_stay_distinct_and_re_running_is_idempotent` |
| The constraint, not `address_id`, does the deduping | `the_unique_constraint_dedupes_on_the_geo_hour_side_key_not_address_id` |
| `org_id` is load-bearing | `a_kit_reassigned_between_orgs_within_an_hour_records_one_check_in_per_org` — verified to FAIL if `org_id` is removed from the key |
| Zero-geo rows can never become a `(0,0)` row | `test_a_zero_geo_book_row_is_never_elected_so_no_null_island_row_can_be_written` — verified to FAIL if the `addresses` CTE filter is removed |
| Determinism, not bit-exactness, is what the key needs | `geo-key-float-precision.md` (measured PHP `precision`/`serialize_precision` behaviour) |
| The re-geocoding residual is real but rarer | `ac3-measurement-procedure.md` — 24.92% of 198,300,757 `zod.public_address` rows have `_peerdb_version > 0`, ~166k/day synced |

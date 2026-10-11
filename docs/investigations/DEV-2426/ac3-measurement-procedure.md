# DEV-2426 — AC3: the before/after measurement, and who does what

AC3 asks for *"the measured `recorded ÷ kits_processed` figure from the `scout:record-check-ins:
completed` log line … before and after."*

Per the Phase 2 decision (Dalton, 2026-08-25): the **"after" figure is a post-merge follow-up and
does not gate this ticket's Done.** The PR ships the "before" figure plus the exposure sizing in
`flip-risk-measurement.md`.

## Why neither figure arrives on its own

`routes/console.php:59-65` has the `scout:record-check-ins` schedule **commented out** (commit
`4aec6244`, 2026-08-21). Nothing runs the recorder on a cadence, so both figures require a
**deliberate manual invocation on production**. That is an operator action — it writes rows to
`platform.scout_check_ins` — and is outside what this pipeline performs.

## The sequence, which must not be reordered

There is no automated production migration path: `app:deploy` and the composer post-deploy hook
never run `migrate`, and `php artisan migrate` on production fails outright because the deploy DB
user lacks `CREATE` on the `zod` database (`ensureAppSchemaExists()` throws even on a no-op). So:

1. **"Before" run** — on current production code (which already includes DEV-2414), invoke
   `scout:record-check-ins` manually. Repeat a few cycles so the figure is not a single sample;
   DEV-2414's baseline used 9 runs. Read `recorded` and `kits_processed` off the
   `scout:record-check-ins: completed` log line and compute the ratio.
2. **Hand-apply the DDL** (the exact statements are in `plan.md`), then
   `ALTER TABLE platform.scout_check_ins OWNER TO graphqluser`.
3. **Deploy the code.**
4. **"After" run** — same method as step 1, same number of cycles.

**Steps 2 and 3 must not be inverted.** If the code ships before the DDL exists, every kit's insert
references `addr_lng`/`addr_lat` columns that are absent. The per-kit `try/catch` in
`recordCheckInsForKit()`'s caller means this does **not** crash the command — it logs
`scout:record-check-ins: kit failed` per kit and reports a completion line with `recorded: 0` and
`skipped` equal to the fleet size. So the failure mode is a **silent zero**, not an outage, which
is exactly the kind that goes unnoticed. Deploying step 3 first is therefore recoverable but
invisible; do the DDL first.

## The deploy boundary writes duplicates for one hour — and they land in the measurement

Found in review round 1 (lane claude2, finding D1) and it changes the procedure, so it is called
out before the caveats rather than after.

Because legacy rows are deliberately **not** backfilled, they keep `addr_lng = NULL`. And because
the old `unique(kit_id, address_id, hour_bucket)` is **dropped**, nothing links a legacy row to the
post-change row for the same visit. So for the single hour that straddles the deploy:

- 11:20 — "before" run, old code, writes `R = (kit 7, address 100, hour_bucket 11:00)`.
- 11:35 — DDL hand-applied. `R.addr_lng` is `NULL`; the old constraint is gone.
- 11:40 — new code deployed.
- 11:45 — "after" run. The lookback covers 11:30-11:45; the kit is still parked at that place, so
  the matcher emits `hour_bucket = 11:00` with a real geo. Postgres treats `R`'s `NULL` geo as
  distinct, so there is no conflict — **a second row is written for the same visit in the same
  hour.**

Two consequences:

1. **AC1 is violated for the straddling hour**, by the migration itself rather than by the bug.
2. **The "after" figure is inflated by those boundary duplicates**, so the naive procedure would
   measure the fix against an artifact the procedure creates.

**The mitigation is free, but the hour boundary alone is NOT enough** — an earlier draft of this
document said "start at 12:00" and that was arithmetically wrong (caught in review round 2, lane
claude2). At 12:00 the lookback is `$since = 11:45`, and fixes from 11:45-11:59 still have
`toStartOfHour = 11:00`, so the matcher still emits `hour_bucket = 11:00` and still collides with
the legacy NULL-geo row.

**The correct rule: start the "after" runs at least one full `lookback_minutes` PAST the hour
boundary.** With the default 15-minute lookback and a deploy finishing at 11:40, start at **12:15 or
later** — then `$since = 12:00`, every fix in the window has `hour_bucket >= 12:00`, and no legacy
row exists for those hours. If `lookback_minutes` is changed, this offset changes with it.

If for some reason the "after" figure must be taken inside the straddling hour, it is not
comparable to the "before" figure and should not be quoted as the improvement.

## A fourth duplication path this change does NOT close

Round 2 (lanes claude1 D1, claude2 D2) flagged that moving the storage identity onto the address
*geo* introduces a mirrored version of the very problem it fixes: the three flip mechanisms were all
"the elected **address** changes"; the new one is "the elected **geo** changes".

Mechanism: run 1 (book rebuild N) writes `(kit, org, G, 11:00, false)` for address 100. The
underlying `zod.public_address` row is then corrected or re-geocoded, so rebuild N+1 carries address
100 at `G'`. Run 2, same hour, emits `(kit, org, G', 11:00, false)` — a different key, no collision,
**a second row for one physical visit.**

Measured bound on the driver (read-only, `zod.public_address` CDC mirror, 2026-08-26): of
**198,300,757** live address rows, **49,407,213 (24.92%)** carry `_peerdb_version > 0`, i.e. have
been updated at least once since first insert, and **1,163,924** were synced in the last 7 days
(~166k/day). Not every update changes `geo`, so this is an **upper bound** on the driver, not the
rate of the defect itself — but it establishes that address correction happens continuously at
scale rather than being hypothetical. DEV-2427 exists specifically because geocoder-fallback
centroids get corrected.

Why this is recorded rather than fixed: closing it needs a **stable place identifier** that survives
re-geocoding, which does not exist in the book today — the geo *is* the only place identity
available. Both the old key (mutable elected `address_id`) and the new key (mutable elected `geo`)
are derived, and the new one is strictly better because order churn — measured at 33.3% of election
groups being flip-capable with six-figure daily order-status churn — is far more frequent than
address re-geocoding. Trading a frequent failure for a rarer one of the same shape is the intended
improvement, not an oversight; it just needs saying out loud.

Practical consequence for AC3: like the two-rows-per-side grain, this puts a small floor above 1.0
that no amount of measurement window will remove.

## Comparability caveat, carried over from DEV-2414

DEV-2414's `ac4-prediction.md` recorded that matcher **output** rows and net **`recorded`** rows are
different quantities, because `insertOrIgnore` absorbs the ~3x overlapping re-matches. The
before/after figures here are both net `recorded ÷ kits_processed`, so they ARE comparable to each
other — and to the 2.07 baseline in DEV-2414 comment 38511, which was measured the same way.

One thing to expect: because the Phase 2 decision keeps **two rows per place** when a place carries
both a Direct and a Staging order (AC4's grain), the post-fix figure has a floor above 1.0 wherever
that co-location exists. The ~1.0 target is the figure for a single-sided place. Reading the
"after" number as a failure because it sits above 1.0 would be a misreading of the chosen grain.

Note there are now **two** distinct reasons the figure can sit above 1.0 — the two-rows-per-side
grain (permanent, by design) and the deploy-boundary duplicates (transient, one hour only, and
avoidable by the timing rule above). Measuring after the hour boundary removes the second, which is
what makes the remainder attributable to the grain.

## What the PR can and cannot claim

- **Can:** the exposure sizing (33.3% of (geo, side) election groups are flip-capable; 59.2% of
  book rows sit in such a group; six-figure daily order churn) — measured read-only, already in
  `flip-risk-measurement.md`.
- **Can:** that all three flip mechanisms are now covered by tests that fail against the old key.
- **Cannot:** a realised before/after improvement. A retrospective flip rate is not recoverable at
  all — the book is rebuilt in place every 15 minutes and past states are not retained.

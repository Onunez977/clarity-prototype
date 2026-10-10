# DEV-2426 — the flip-risk population, measured read-only against production ClickHouse Cloud

The ticket says of the winner-flip rate: *"The rate is unmeasured — it is bounded by book churn,
not by rarity, so please don't assume it is small without measuring."* This is that measurement.

**Method.** Pure `SELECT` against ClickHouse Cloud (`clarity.scout_open_addresses`, the live
fleet-wide open-order book, and `zod.public_hotlist`, the CDC mirror of production Postgres). No
writes. One book snapshot (the book is rebuilt in place every 15 minutes and past states are not
retained, so a *historical* flip rate is not recoverable — what is measurable is the **population
at risk** and the **churn that drives it**). Query shapes and parameter classes are in
`derivation_log`; no raw rows left ClickHouse.

## 1. The at-risk population — flip mechanisms 1 and 2

Both mechanism 1 (a lower `address_id` opens at the geo) and mechanism 2 (the current winner's
order closes) require **two or more addresses competing at the same geo within the same
`is_staged` bucket**. That is exactly the `ROW_NUMBER() OVER (PARTITION BY addr_lng, addr_lat,
hour_bucket, out_is_staged)` group. Its size distribution:

| addresses in the (geo, side) group | groups | book rows |
|---|---|---|
| 1 | 1,845,850 | 1,845,850 |
| 2–9 | 909,934 | 2,420,222 |
| 10–99 | 11,971 | 184,529 |
| 100–999 | 106 | 22,519 |
| 1000+ | 10 | 46,827 |
| **total** | **2,767,871** | **4,519,947** |

- **33.3%** of (geo, side) election groups hold more than one address (922,021 / 2,767,871).
- **59.2%** of book rows sit inside such a group (2,674,097 / 4,519,947).

So a third of the places the matcher elects a winner for are structurally capable of a flip, and
in every such group the flip is *deterministic* the moment the lowest `address_id` leaves: the
next-lowest takes over. This is not a rare-event tail.

## 2. The churn that fires it — mechanism 2

`zod.public_hotlist` (CDC mirror, `FINAL`, `_peerdb_is_deleted = 0`), grouped by status:

- Open (`status = 1`): **46,059,715** rows, of which **1,015,874 were updated in the last 24 h**.
- Non-open statuses combined: **~212,700 rows updated in the last 24 h** (a lower bound on
  transitions *out* of Open — some of those updates are non-status edits).
- The book itself spans **1,623,778 distinct `hotlist_id`s**.

Order closure is continuous at six figures a day fleet-wide. Combined with §1, mechanism 2 —
which the ticket calls "the frequent one" — has both a large eligible population and a
continuous trigger.

## 3. Mechanism 3 needs restating — verified

The ticket (and `ScoutCheckInMatchQuery`'s docblock) describe mechanism 3 as: *a Staging address
that later **also joins** a Direct hotlist has `min(is_staged)` move it to the Direct bucket.*
That phrasing implies the address is **simultaneously** on both a Direct and a Staging hotlist.

Measured on the live book:

| metric | value |
|---|---|
| rows | 4,519,947 |
| distinct `address_id` | 4,519,947 |
| distinct `(hotlist_id, address_id)` | 4,519,947 |
| distinct `hotlist_id` | 1,623,778 |
| `address_id`s carrying **both** `is_staged` values | **0** |

Every `address_id` appears **exactly once** in the book — on exactly one hotlist, on exactly one
side. So `min(is_staged)` never has two values to choose between for one `address_id`, and the
**simultaneous** dual-listing variant of mechanism 3 does not occur in the current book at all.

This does **not** retire mechanism 3. The damaging condition is *the same `address_id`'s
`is_staged` differing between two book snapshots* — an address moving from a Staging hotlist to a
Direct one across a rebuild. That is temporal, needs no dual-listing, and is not visible in a
single snapshot. The practical consequence for the fix is that it must handle **the label
changing between runs**, whatever produced the change — not specifically a `min(is_staged)`
collapse.

Two further consequences worth carrying into review:
- `ScoutCheckInMatchQuery`'s `argMin(hotlist_id, (toUInt8(is_staged), hotlist_id))` Direct-wins
  tiebreak, and the parity tests that pin it, currently guard a book state with **zero**
  production instances. Defensive, not wrong — but it means "Direct wins" is presently untested
  against real data.
- The docblock's "geos carrying both sides are ~0.27% of the geos an org sees" is a **per-org
  view-weighted** figure and remains the right basis for per-kit reasoning. The fleet-wide
  equivalent is **17.13%** (404,786 of 2,363,085 geos), because one geo is frequently org A's
  Direct and org B's Staging. These are different quantities; the docblock already warns against
  conflating them, and this measurement does not contradict it.

## 4. What this does not measure

- **Not the flip rate itself.** No historical book states exist, so the realised
  flips-per-hour figure cannot be recovered retrospectively. §1 and §2 bound the exposure; they
  do not replace AC3's before/after.
- **Not per-kit.** These are fleet-wide book figures. A given kit's bbox sees a biased slice —
  DEV-2414 measured that bias directly (its per-kit sample came in at 1.54x against a naive
  fleet-wide 1.91x), so per-kit flip exposure should be expected to differ from 33.3%.
- **One snapshot**, taken 2026-08-25.

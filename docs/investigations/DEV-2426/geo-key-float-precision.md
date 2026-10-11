# DEV-2426 — why raw `double precision` columns are a safe uniqueness key

The new key stores the elected **address** coordinate. The worry is whether a float survives the
trip `ClickHouse Float64 → HTTP → PHP float → PostgreSQL double precision` well enough for a
UNIQUE index to recognise two emissions of the same place as duplicates.

One prep candidate justified this as *"ClickHouse's FORMAT JSON round-trips float64 bit-exactly"*.
That is only half right, and the half that is wrong matters, so here is the measured behaviour on
this machine (PHP 8.4.23; production is 8.3 — these are ini defaults, not version-specific):

```
precision=14   serialize_precision=-1

orig   = -97.123456789012337        (the actual binary64)
(string) cast  = -97.123456789012   → round-trips back to the same double? NO
json_encode()  = -97.12345678901234 → round-trips back to the same double? yes
```

So:
- `json_encode` (governed by `serialize_precision=-1`, shortest-round-trip) **is** exact.
- A plain **string cast is not** — it drops to 14 significant digits. This matters because PDO
  binds float parameters as strings on the common path, so the value that actually lands in
  Postgres is generally the **14-significant-digit form**, not the exact double.

**The key is still sound, but for a different reason: determinism, not bit-exactness.**

A unique index does not need the stored value to equal the ClickHouse bits. It needs the *same
input* to always produce the *same stored value*. The 14-digit truncation is a pure function of
the input double, so two emissions of the same address geo always store byte-identical values and
collide correctly. Under-collapse (a duplicate slipping through) is therefore impossible.

The only residual risk is **over-collapse**: two coordinates ClickHouse treats as distinct that
truncate to the same 14-digit string. On a coordinate of magnitude ~100, 14 significant digits
leaves ~12 fractional digits — about **1e-12 degrees, on the order of 0.1 micrometres**. For
comparison, `ScoutCheckInMatchQueryParityTest::test_near_but_not_identical_coordinates_do_not_collapse`
pins a pair ~1 m apart (1e-5 degrees) as distinct — seven orders of magnitude coarser than the
truncation. Nothing in the book can realistically be caught by this.

## Why not the alternatives

- **Geohash** (`App\Support\Scout\Geohash` + CH `geohashEncode()`, already used as a dedup bucket
  in `HitDirectHotspotQuery`): its precision there is a deliberately coarse bucket (tens of
  metres). Coarse enough to collapse geos the election keeps distinct — i.e. a *deliberate* grain
  divergence between the ClickHouse election and Postgres storage, which is exactly the class of
  drift DEV-2414's docblock warns about. Fine precision would instead reintroduce boundary jitter
  (two nearby points landing in different cells).
- **PostGIS point**: the default `=` operator on `geometry` is **bounding-box** equality, not exact
  coordinate equality — strictly worse than plain doubles for a uniqueness key.
- **Rounding to a fixed decimal** (e.g. 6 dp ≈ 0.11 m): defensible, but it *changes* the grain
  rather than preserving it, and it would need its own decision about what "same place" means.
  Raw doubles preserve precisely the grain the CH `PARTITION BY addr_lng, addr_lat` already uses.

## What this means for the implementation

Two nullable `double precision` columns holding the book address's coordinate. The pinning test
should assert the *behaviour that matters* — that a second recorder run over an hour whose elected
winner changed does not add a row — rather than asserting bit-equality of the stored float, which
is not what the fix depends on.

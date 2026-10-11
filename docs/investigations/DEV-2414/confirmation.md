# DEV-2414 — how the change was confirmed

Counting rows is not confirmation. Old-vs-new **counts** matching would not prove the same
addresses were elected. This is the differential invariant check that does, run read-only against
production ClickHouse across **17 live kits** (one 15-minute window each, real orgs, real staging
partners, real bbox + 160.934 m radius).

For each kit the OLD grain and the NEW grain were computed side by side from the same `matches`
CTE, and a third expression derived — **independently of the new code** — what the winner *should*
be. Then five invariants were counted.

| invariant | what a violation would mean | violations |
|---|---|---|
| **I1** every NEW `(address_id, hour_bucket)` exists in OLD | the rewrite invented a row | **0** |
| **I2** every OLD `(geo, hour)` still present in NEW | a physical location was silently lost | **0** |
| **I3** NEW's elected winner == `argMin(address_id, (is_staged, address_id))` over OLD's members at that geo | the election rule is wrong | **0** |
| **I4** NEW's `hotlist_id`/`is_staged` per address == OLD's | the per-address `argMin` tie-break regressed | **0** |
| **I5** each `address_id` carries exactly one geo in the book | the fix's core assumption is false | **0** |

**264 OLD rows → 131 NEW rows. Zero violations of any invariant.** Per-kit detail:
the local-only per-kit table (not committed — see above).

## Why each invariant matters

- **I3 is the real proof of correctness.** `want_address_id` is derived from the OLD result set by
  an expression that shares no code with the new query. NEW agreeing with it on every geo means the
  election rule is not merely *self-consistent* — it matches an independently-computed expectation.
- **I4 closes the design hazard flagged before implementation.** The concern was that folding the
  per-address `argMin(hotlist_id, (is_staged, hotlist_id))` into a single-pass rank would let
  ClickHouse pick arbitrarily among rows tying on both sort keys. Zero violations across 264 rows
  confirms the two-stage nesting preserves it.
- **I5 empirically closes the finding three review lanes raised.** They argued one `address_id`
  carrying two geos would double-count. That was refuted *structurally* by reading chlocal
  migration 111 (its `parsed` CTE emits one coordinate per address id). This is the independent
  **empirical** confirmation on live data — and I2 rules out the inverse failure.

## An incidental confirmation

Writing this check, I hit `Code: 184 ILLEGAL_AGGREGATION` by aliasing `min(is_staged) AS is_staged`
alongside the `argMin` — the exact ClickHouse 25.8/26.2 analyzer trap the implementation worker hit
and worked around by renaming the CTE-local aliases to `out_hotlist_id` / `out_is_staged`.
Reproducing it independently confirms that deviation was necessary, not cosmetic.

## What this does and does not confirm

**Confirms:** the rewrite is behaviour-preserving apart from the intended collapse, at production
cardinality, on live data — no invented rows, no lost locations, the correct winner every time, the
tie-break intact, and the geo assumption holding in fact as well as by construction.

**Does not confirm:** the AC-4 volume outcome (that is the separate prediction — ~1.35/kit, see
`ac4-prediction.md`), nor the residual-gap winner-flips, which need multiple cycles with book churn
and cannot be seen in a single window.

---

## RE-VERIFIED AGAINST THE SHIPPED SQL (2026-08-25)

**The check above validated the SUPERSEDED per-geo query.** After the DEV-1756 resolution changed the
partition to `(addr_lng, addr_lat, hour_bucket, out_is_staged)`, that evidence no longer certified
the shipped code — a review lane caught it. Re-run against the shipped SQL, 14 production kits,
**219 OLD rows → 114 NEW**:

| invariant | violations |
|---|---|
| I1 every NEW `(address_id, hour)` exists in OLD | **0** |
| **I2 every OLD `(geo, hour, is_staged)` bucket still present in NEW** | **0** |
| I3 NEW's winner == `min(address_id)` over OLD's members **in that bucket** | **0** |
| I4 NEW's `hotlist_id`/`is_staged` per address == OLD's | **0** |
| I5 each `address_id` carries exactly one geo | **0** |

**I2 is the one that changed and the one that matters.** It is now bucket-aware, so "no lost
`(geo, hour, bucket)`" is direct proof that the staging side survives — the property the whole
DEV-1756 resolution exists to deliver. Per-kit detail was kept local-only; the totals and the zero-violation result above are the committed record.

The earlier per-geo run is retained above as the record of the superseded design, clearly labelled.

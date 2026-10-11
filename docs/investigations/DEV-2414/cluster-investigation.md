# DEV-2414 — exact-geo cluster investigation

**Why:** the operator elected (2026-08-24) to determine whether the largest exact-geo cluster in
`clarity.scout_open_addresses` is a **real shared address** or a **geocoder-fallback centroid**
before choosing the dedup rule. That verdict decides whether exact-Point equality ships as the
ticket specifies, or whether a mass-shared-coordinate guard is needed.

All queries were **read-only** against ClickHouse Cloud. Aggregates only — no raw rows, no plates,
no address text, no identifiers beyond `org_id` and the coordinates already published on the ticket.

## Verdict: the mega-clusters are geocoder-fallback / sentinel artifacts, NOT real shared addresses.

The giveaway is the **multi-org signature**. A genuinely shared building (a depot, an apartment
complex, a dealership lot) carries open orders for one repo company or a small handful. These
clusters carry orders for **32 and 49 unrelated repo companies** simultaneously.

| # | coordinate | addresses | distinct hotlists | distinct orgs | staged | reading |
|---|---|---|---|---|---|---|
| C1 | -96.808028, 33.083789 (~Plano/north TX) | 20,402 | 17,722 | **32** | both | fallback centroid |
| C2 | -170.701384, -14.273749 (**Pago Pago, American Samoa**) | 6,482 | 5,379 | **49** | both | sentinel / failed geocode |
| C3 | -74.194708, 40.719326 (~Newark NJ) | 5,272 | **2** | 2 | direct | NOT a fallback — 2 orders carrying a large address list each (skip-trace style) |
| C4 | -96.252287, 34.969406 | 4,986 | 4,749 | 1 (org 165) | staged | single-org fallback |
| C5 | -74.269841, 40.692651 | 4,678 | 37 | 20 | both | mixed |
| C6 | -97.558479, 32.939262 | 2,558 | 2,556 | 20 | both | fallback centroid |

**C2 is on its own conclusive.** No repo company has open repossession orders in American Samoa,
let alone 49 companies with 6,482 of them. That coordinate is what a failed geocode resolves to.

C3 is a genuinely different shape and worth not conflating: only 2 hotlists behind 5,272 addresses
means two orders each carrying a very large address list, not many orders sharing one point.
Geo-dedup collapses those 5,272 to one check-in per hour, which is the intended behaviour
(one physical location visited).

## What this means for the design — ship exact-Point as the ticket specifies

At a fallback centroid **there is no true location signal to preserve**. Those 20,402 orders are not
physically at that point; the geocoder failed and dropped them on a centroid. So the concern that
motivated the investigation — "collapsing to one row hides genuinely distinct open orders" — does
not apply here. There was never a genuine location claim to hide.

The comparison is:

- **Today:** a Scout kit passing within 160.934 m of C1 with those orders open records **20,402
  check-ins in one kit-hour**, every one of them false, in a single bulk `insertOrIgnore`.
- **After this change:** it records **1** — still a false positive inherited from the bad geocode,
  but 20,402x less noise and a bounded write.

So exact-Point dedup is **strictly protective** at exactly the clusters that raised the concern. No
mass-shared-coordinate guard is needed in the matcher for correctness, and adding one would mean
inventing a threshold this ticket has no basis to tune.

## Exposure is latent, not active

No new query needed — this follows from the baseline already posted on the ticket (comment 38511).
The largest single baseline run recorded **138** check-ins across 44 kits. A single kit passing C1
would alone have produced up to 20,402. Therefore **no kit came within 160.934 m of any of these
clusters during the 2-hour baseline window**, and none of the measured 2.07/kit figure is
attributable to them. The fan-out this ticket fixes is the ordinary duplicated-geocode /
multi-unit kind; the mega-clusters are a separate, currently-unfired hazard.

## Follow-up that belongs elsewhere (NOT this ticket)

The real defect is that fallback coordinates are in the open-order book at all. That belongs
upstream in chlocal's `clarity.scout_open_addresses_mv` (migration 111, DEV-2383), which should
exclude known-bad centroids — the same place `status = Open`, `length(plate) > 3` and the EWKT
parse already live. Recommend a separate ticket against chlocal. Fixing it here would mean
re-applying book-level filtering the matcher is explicitly told not to duplicate.

## Derivation log (query shapes only)

| query_shape | param_classes | aggregate_result | why |
|---|---|---|---|
| `SELECT tupleElement(geo,1), tupleElement(geo,2), count(), uniqExact(address_id), uniqExact(hotlist_id), uniqExact(org_id), groupUniqArray(10)(org_id), groupUniqArray(2)(is_staged) FROM clarity.scout_open_addresses GROUP BY 1,2 ORDER BY count() DESC LIMIT 6` | fleet-wide book, no org scoping | the six rows in the table above | the decisive probe: per-cluster org and hotlist spread is what separates a real shared building from a fallback centroid |
| `SELECT ... FROM zod.public_address WHERE _peerdb_is_deleted = 0 AND id IN (<address_ids at C1>)` counting `uniqExact` of house_number/road/city/postcode | 20,402 then 400 sampled address ids | **NOT OBTAINED** — timed out repeatedly against Cloud (197.8M-row ReplacingMergeTree; `FINAL` and non-`FINAL` variants both exceeded 280s) | intended as confirmatory street-diversity evidence; abandoned because the org/hotlist spread already settles the verdict. Recorded as attempted-and-failed rather than omitted. |

## Confidence

**High** on the verdict (multi-org signature + an implausible-territory coordinate are independent
and mutually corroborating). **Medium** only on the precise mechanism (fallback centroid vs
sentinel default vs a specific bad geocoder rule) — distinguishing those needs the street-diversity
query that would not complete, and does not change this ticket's design decision.

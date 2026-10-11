# DEV-2739 Plan t1 — Cloud CH READ-ONLY measure (org 14 · 2026-09-25)

**When:** 2026-09-25 ~14:50 CT · **Cloud:** Production CH HTTPS Query API (`readonly_user`) · database `clarity` · **READ-ONLY**  
**MCP note:** service `0d24f0c2-…` returned “Forbidden. Service does not allow MCP calls”; used HTTPS + `/workspace/first-glance/secrets/ch-cloud-readonly.env`.  
**No product PHP. No Docker fleet. No writes.**

## 1) Locked filters window

| Filter | Value |
| --- | --- |
| Org | **14** (Brad Webb / Premier Adjusters) |
| Hit list scope | **`kit_org_id = 14`** (matches Path B / `Hit::forKits` — not `hotlist_org_id`) |
| Status | Open → `hotlist_status = 1` |
| Disposition | New Hit+Accessible → `disposition IS NULL OR disposition IN ('Accessible')` |
| Hit date | **Today** America/Chicago as of screenshot ~**13:54 CT** → UTC `[2026-09-25 05:00:00, 2026-09-25 18:54:00)` |
| Type / FlexSearch / Cluster / Scanned | Type All; FlexSearch ON / Cluster OFF / Scanned Any — **not enforced in CH** (UI-only); list cohort is the CH predicates above |
| Excludes | `integration_id != 5`, `hit_type != 'O'` (same as `forKits`) |
| Table | `clarity.hits_hot` **no FINAL** (hot path) |

**Cohort size:** **1322** hits · **333** distinct hotlists · **58** kits.

## 2) Scan lat/lng (Path B path)

| Slice | Hits |
| --- | --- |
| Zero / missing scan lat or lng (`lat=0 OR lng=0`) | **0** |
| Usable geo (`lat≠0 AND lng≠0`) | **1322** (100%) |

Zero-geo / formatter-null is **not** the mass explanation for “N/A on every row.”

## 3) Qualifying `clarity.recovery_address FINAL` (Path B source)

Contract (OKF + controller): non-zero `geo.1` / `geo.2`, `_peerdb_is_deleted = 0`. PG MV is type_id=2 Given Address.

| Metric | Count |
| --- | --- |
| Hotlists with ≥1 qualifying RA | **3** |
| Hotlists missing qualifying RA | **330** |
| Hits whose hotlist has qualifying RA | **24** |
| Hits whose hotlist lacks qualifying RA | **1298** |
| Mirror health | ~1.30M `recovery_address` rows; PeerDB sync ~19:39 UTC — **not** empty pipeline |

### Path B present (bug-candidate cohort if UI still N/A)

| hotlist_id | plate | state | hits | RA address |
| --- | --- | --- | --- | --- |
| 198237015 | TYH1103 | TX | 18 | 2409 Fallwood Dr, Arlington, TX 76014 |
| 198236472 | SRG2747 | TX | 5 | 1579 Shelby St, Houston, TX 77009 |
| 198160262 | TBZ2473 | TX | 1 | 1942 Bunzel St, Houston, TX 77088 |

Example hit_ids / plates (Path B present):  
`365042780` TYH1103 · `365042779` TYH1103 · `365042739` TYH1103 · `365042547` SRG2747 · `365042463` SRG2747 · `365041537` TBZ2473

### Path B missing (contract-N/A for Path B) — sample

| hit_id | plate | state | kit | hotlist_id |
| --- | --- | --- | --- | --- |
| 365042773 | PGG979 | OK | 100058 | 198237011 |
| 365042284 | VKV5602 | TX | 100065 | 198236733 |
| 365041886 | RYP7951 | TX | 100058 | 198236549 |
| 365041885 | PLT4957 | TX | 100058 | 198236548 |
| 365041851 | LFF7622 | TX | 100058 | 198236533 |

## 4) Path A contrast (zod · light — for Q3, not a winner pick)

Path A (`ListHits`) uses `hotlist_address` ⋈ `address` with `geo IS NOT NULL` (any geocoded address — **not** type_id=2-only).

Of **330** Path-B-missing hotlists:

| Slice | Hotlists | Hits (approx) |
| --- | --- | --- |
| Path B miss **and** Path A has ≥1 geocoded address | **260** | **1024** |
| Both paths miss geocoded given/any | **70** | **274** |

Sample Path-B-miss / Path-A-hit: hotlist `118237625` plate **TGR0553** TX — Path A geo e.g. `POINT (-96.45911 32.883706)` @ 242 Lakeside Dr; hotlist `158762679` **TPJ3266** TX — multiple Path A geos.  
Note: zod `public_address.type_id` was **NULL** on these rows in CH; Path A still joins on non-null `geo`.

**Implication:** For ~1024/1322 hits, **Path A would likely compute a distance while Path B returns N/A** — real path disagreement. Do **not** auto-pick Path A as the fix until Q3 (Dalton after this packet).

## 5) Plain classify

1. **Dominant (~1298/1322 hits, 330/333 hotlists) — contract-N/A (data) for Path B**  
   Usable scan geo, **no** qualifying `recovery_address` → UI N/A matches Path B contract. Not a mass zero-geo / formatter bug. Glance hard stop: do not invent a product fix solely for missing given-address data without Dalton.

2. **Minority (24 hits / 3 hotlists) — bug-candidate**  
   Qualifying Path B RA **exists**; Dalton reported **every** visible row N/A at ~13:54 CT. If those three plates were on-screen (or any Path-B-present row showed N/A), that is **controller/blade/UI miss**, not missing data. Prefer M0 Network confirmation; data alone already flags the cohort.

3. **Path A contrast — needed for Q3**  
   **260** Path-B-empty hotlists still have Path A geocoded addresses (**1024** hits). Strong disagreement packet. **Do not** choose Path A vs B winner in Plan — park for Dalton after measure.

## Cite queries (READ-ONLY)

```sql
-- Locked cohort + geo split
SELECT
  count() AS hits,
  countIf(lat = 0 OR lng = 0) AS zero_geo,
  countIf(lat != 0 AND lng != 0) AS usable_geo,
  uniqExact(hotlist_id) AS hotlists
FROM clarity.hits_hot
WHERE kit_org_id = 14 AND integration_id != 5 AND hit_type != 'O'
  AND hotlist_status = 1
  AND (disposition IS NULL OR disposition IN ('Accessible'))
  AND hit_created_at >= toDateTime('2026-09-25 05:00:00')
  AND hit_created_at <  toDateTime('2026-09-25 18:54:00');

-- Qualifying RA coverage
WITH cohort AS (
  SELECT DISTINCT hotlist_id FROM clarity.hits_hot
  WHERE /* same predicates */ AND lat != 0 AND lng != 0
)
SELECT
  uniqExactIf(hotlist_id, hotlist_id IN (
    SELECT id FROM clarity.recovery_address FINAL
    WHERE geo.1 != 0 AND geo.2 != 0 AND _peerdb_is_deleted = 0
  )) AS hotlists_with_ra,
  uniqExactIf(hotlist_id, hotlist_id NOT IN (
    SELECT id FROM clarity.recovery_address FINAL
    WHERE geo.1 != 0 AND geo.2 != 0 AND _peerdb_is_deleted = 0
  )) AS hotlists_missing_ra
FROM cohort;

-- Path B present examples
SELECT hotlist_id, any(hotlist_plate), any(hotlist_us_state), count()
FROM clarity.hits_hot
WHERE /* cohort */ AND hotlist_id IN (
  SELECT id FROM clarity.recovery_address FINAL
  WHERE geo.1 != 0 AND geo.2 != 0 AND _peerdb_is_deleted = 0
)
GROUP BY hotlist_id;

-- Path A contrast on Path-B-missing
-- zod.public_hotlist_address ⋈ zod.public_address WHERE geo IS NOT NULL AND geo != ''
```

## Next (still Plan — not Implement)

- Deliver this packet to Dalton (via Gandalf).  
- Await Q3 Path A vs B after disagreement review.  
- **Provision + Implement remain parked** until DEV-2736 / #468 Off GO clear (bounce-back lock).  
- Optional M0: Network on one Path-B-present plate (TYH1103 / SRG2747 / TBZ2473) while still N/A.


## See also

Q3 multi-org Path A vs Path B contrast: [`measure-q3-contrast.md`](./measure-q3-contrast.md) (2026-09-25).

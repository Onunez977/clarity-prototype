# DEV-2739 Q3 — Path A vs Path B multi-org contrast (Cloud CH READ-ONLY)

**When:** 2026-09-25 ~14:55 CT · **READ-ONLY** Cloud CH HTTPS (`readonly_user`) · zod PG mirror for Path A  
**Extends:** `measure.md` (org 14 t1) — does not reinvent  
**No product PHP · No Docker fleet · Provision/Implement PARKED**  
**Winner:** **not picked** — packet only

## Locked filter shape (all orgs)

| Filter | Value |
| --- | --- |
| Scope | `kit_org_id IN (…)`, `integration_id != 5`, `hit_type != 'O'` |
| Status / Disposition | Open (`hotlist_status=1`) · New Hit+Accessible |
| Window | Today America/Chicago through ~13:54 CT screenshot → UTC `[2026-09-25 05:00:00, 18:54:00)` |
| Table | `clarity.hits_hot` (no FINAL) |
| Path B qualify | `clarity.recovery_address FINAL` · `geo.1/geo.2 != 0` · not deleted |
| Path A qualify | `zod.public_hotlist_address` ⋈ `zod.public_address` · `geo IS NOT NULL AND geo != ''` |

**Orgs (≥5 including Brad Webb 14):** 1271, **14**, 541, 418753, 1143 (top volume under same window).

| kit_org_id | Name |
| --- | --- |
| 14 | Premier Adjusters, Inc (Brad Webb) |
| 1271 | Collateral Recovery Team |
| 541 | Stealth Towing And Recovery Services Llc |
| 418753 | Elite Towing & Recovery Inc. |
| 1143 | RELENTLESS RECOVERY |

## Per-org hit-level contrast

| org | hits | usable geo | zero geo | Path B present | Path B absent | Path A present | Path A absent | agree both | agree neither | **B-miss A-hit** | B-hit A-miss |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1271 | 2745 | 2745 | 0 | 45 | 2700 | 2464 | 281 | 45 | 281 | **2419** | 0 |
| **14** | 1323 | 1323 | 0 | 24 | 1299 | 1049 | 274 | 24 | 274 | **1025** | 0 |
| 541 | 919 | 919 | 0 | 33 | 886 | 834 | 85 | 33 | 85 | **801** | 0 |
| 418753 | 824 | 824 | 0 | 0 | 824 | 517 | 307 | 0 | 307 | **517** | 0 |
| 1143 | 810 | 810 | 0 | 11 | 799 | 611 | 199 | 11 | 199 | **600** | 0 |
| **Σ** | **6624** | **6624** | **0** | **113** | **6511** | **5475** | **1149** | **113** | **1146** | **5365 (81%)** | **0** |

(Org 14 hits drifted 1322→1323 vs earlier t1 as window stayed open — same predicates.)

## Hotlist-level (usable-geo cohort)

| org | hotlists | Path B | Path A | agree both | agree neither | **B-miss A-hit** | B-hit A-miss |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1271 | 520 | 7 | 446 | 7 | 74 | **439** | 0 |
| 14 | 333 | 3 | 263 | 3 | 70 | **260** | 0 |
| 541 | 218 | 2 | 190 | 2 | 28 | **188** | 0 |
| 1143 | 178 | 2 | 137 | 2 | 41 | **135** | 0 |
| 418753 | 106 | 0 | 79 | 0 | 27 | **79** | 0 |

## Pattern (facts only — no winner)

1. **Zero scan geo = 0** across all five orgs — not a mass formatter/zero-geo bug.
2. **Dominant bucket = Path B miss + Path A hit** (~81% of hits). Path A would often compute a distance where Path B returns N/A (missing qualifying `recovery_address`).
3. **Path B present is rare** (113 hits / ~14 hotlists in sample). When Path B is present, Path A is **always** also present (`B-hit A-miss = 0`).
4. **Agree neither** (~17%) = both paths lack geocoded given/any address → contract N/A either way.
5. Org **418753** has **zero** Path B RA in this window (all Path B absent); still 517/824 Path A hits.

## Concrete disagreement examples (B-miss · A-hit)

| org | hotlist_id | plate | st | sample hit_id | hits | Path A street / geo |
| --- | --- | --- | --- | --- | ---: | --- |
| 14 | 198227363 | SHK5039 | TX | 365015475 | 39 | 4917 Cedar River Trl · POINT (-97.276107 32.884759) |
| 14 | 198225827 | SMS0474 | TX | 365005175 | 28 | 3655 Prairie Waters Dr · POINT (-97.073002 32.605127) |
| 1271 | 198229869 | SWR4032 | TX | 365027578 | 66 | 12660 Uhr Ln · POINT (-98.404503 29.555694) |
| 1271 | 198226396 | VMS3128 | TX | 365012598 | 49 | 2618 Nacogdoches Rd · POINT (-98.448219 29.519286) |
| 541 | 198226372 | WVY6077 | TX | 365008759 | 28 | 1816 Western Center Blvd · POINT (-97.338709 32.862063) |
| 541 | 198226380 | XHN9682 | TX | 365013297 | 28 | 2801 Las Vegas Trl · POINT (-97.466273 32.735298) |
| 418753 | 198237082 | CJ83591 | IL | 365042451 | 94 | 508 E Mulberry Ct · POINT (-87.59951 41.545714) |
| 418753 | 198227185 | EQ88612 | IL | 365014254 | 32 | 5166 S Indiana Ave · POINT (-87.621329 41.800065) |
| 1143 | 198209535 | HHM6700 | OH | 364989440 | 43 | 20100 Lorain Rd · POINT (-81.841706 41.450837) |
| 1143 | 198203953 | KEX4043 | OH | 364956290 | 31 | 3312 Niagara St · POINT (-84.589721 39.246941) |

## Path B present examples (rare; bug-candidate *if* UI still N/A)

| org | hotlist_id | plate | st | hits | sample hit_id | RA address |
| --- | --- | --- | --- | ---: | --- | --- |
| 14 | 198237015 | TYH1103 | TX | 18 | 365041779 | 2409 Fallwood Dr, Arlington TX 76014 |
| 14 | 198236472 | SRG2747 | TX | 5 | 365038193 | 1579 Shelby St, Houston TX 77009 |
| 14 | 198160262 | TBZ2473 | TX | 1 | 365041186 | 1942 Bunzel St, Houston TX 77088 |
| 1271 | 198233250 | WGG0487 | TX | 16 | 365034267 | 304 Betty Jean St, San Antonio TX 78223 |
| 541 | 198226382 | WVY6077 | TX | 28 | 365013317 | 301 Intermodal Pkwy, Fort Worth TX 76177 |
| 1143 | 198225586 | KZL5304 | OH | 6 | 365003434 | 4992 Mt Alverno Rd, Cincinnati OH 45238 |
| 418753 | — | — | — | 0 | — | none in window |

## Cite (core)

```sql
-- Path B set
SELECT DISTINCT id FROM clarity.recovery_address FINAL
WHERE geo.1 != 0 AND geo.2 != 0 AND _peerdb_is_deleted = 0;

-- Path A set
SELECT DISTINCT ha.hotlist_id
FROM zod.public_hotlist_address AS ha FINAL
INNER JOIN zod.public_address AS a FINAL ON a.id = ha.address_id
WHERE ha._peerdb_is_deleted = 0 AND a._peerdb_is_deleted = 0
  AND a.geo IS NOT NULL AND a.geo != '';

-- Cohort: kit_org_id IN (14,1271,541,418753,1143) + locked filters + window
```

## Still open for Dalton

- Pick Path A vs Path B lane (or hybrid) after this disagreement packet — **Aragorn does not pick**.
- Optional M0 Network on a Path-B-present plate still showing N/A.
- Provision/Implement remain behind #468 Off GO.


## See also

Path B gap dive (type_id seam): [`measure-path-b-gap.md`](./measure-path-b-gap.md).

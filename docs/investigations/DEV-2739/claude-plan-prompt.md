You are an independent Ultra-Plan co-pilot for DEV-2739 (insight-lpr/platform).
Read-only tools only (Read, Grep, Glob). Do not edit files, commit, push, or touch env/DSN/Herd/fleet. Cloud ClickHouse is READ-ONLY measure only — do not write.

Gate is CLEAN (Dalton via Gandalf 2026-09-25):
Q1 Reproduce locked:
- Org ID 14 (Brad Webb / brad.webb@premieradjusters.com / premieradjusters)
- Viewer: ADMIN MODE — DaltonMcCleery impersonating brad.webb@premieradjusters.com (likely Path B)
- Filters: Type All · Status Open · FlexSearch ON · Location Cluster OFF · Disposition New Hit + Accessible · Hit date Today · Scanned date Any
- Every visible row: Distance from Given = N/A AND Nearest Given Address = N/A

Q2: Research and measure first (Cloud CH READ-ONLY) before calling out-of-scope data vs product fix. Do not invent a product change on missing geocoded given-address rows until measure lands.

Q3: Defer Path A vs Path B as product “given address” until they disagree on the same hits after measure. Do not invent a winner.

Canon: okf/concepts/hit-address-distance.md (main #467). Path A = ListHits::buildPostgresQuery hotlist_address⋈address geo IS NOT NULL. Path B = blades → NearestGivenAddressController → clarity.recovery_address FINAL (type_id=2). Soft relate DEV-1611 Done — not a reopen.

Read docs/investigations/DEV-2739/* and the cited PHP/blades/controller/OKF on this branch.
Write a concise independent implement plan: measure-first classification steps (Cloud CH RO + optional PG contrast), then smallest fix branches only after measure classifies data vs query vs UI; files/methods; tests; regression org; what not to touch. Prefer simple wins (#24). Reject new types/architecture. Never invent root cause or Cloud writes.

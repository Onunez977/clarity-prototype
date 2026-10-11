# DEV-2659 — Question Gate

**Gate:** CLEAN.

Item 5 / AC-7 (drop `forwarder_org_id` from the uniq key) is locked out of ship. It is not a question.

## Q1 — answered

After `is_internal = 0` deletes the needle LEFT JOINs, the `orgs` CTE has no remaining reader. It does not ship.

Proven on `a4b50746`:

- `UniqueLprCaseClaimsQuery` reads `orgs` only from the two LEFT JOINs in `kept` (lines 275–276). The CTE is lines 252–256.
- `LprRepossessionsQuery` reads `orgs` only from the two LEFT JOINs in `kept` (lines 191–192). The CTE is lines 167–196.
- Ticket scope item 2 deletes those joins.
- `clarity.billing_claim_events_mv` (schema line 102) sets `is_internal` from `zod.public_orgs FINAL` over all org types.

**Answer:** delete the CTE and the needle joins. Do not swap the source to `clarity.orgs`. Do not add a type IN (1, 2) docblock. Hazard 2 does not apply to this read path.

Comment 41325 already measured the four insight orgs as types 1, 2, 3, and 4, with zero claims on the two types outside (1, 2). This run did not re-measure Cloud ClickHouse.

Comment 41330 still names an in-query `clarity.orgs` dedup. That sentence is journaled. The code and the steering delete the unused path.

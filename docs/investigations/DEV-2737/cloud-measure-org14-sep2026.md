# DEV-2737 Cloud CH READ-ONLY measure — Org 14 LPR Repos

Measured: 2026-09-28 ~09:28 CT against ClickHouse Cloud Production via `ch-cloud-readonly` (`readonly_user`). **No writes.**

## Method
Mirrored `LprRepossessionsQuery::monthlySeries(14, now('UTC'))` SQL shape (13-month bucketed series):
- Window: `2025-09-01` → `2026-09-30` UTC (`ts` 1756684800–1790812799; `event_date` same; status-log floor `2025-08-25`)
- `clarity.billing_claim_events_v`: `is_internal=0`, `event_type='case-claim'`, org 14 as forwarder **or** agent
- Dedup: `BillingClaimDedup::dedupedCteSql()` (`success AND vin != ''`, `min(claim_date)`)
- Joins: status-3 `zod.public_hotlist` FINAL + `zod.public_hotlist_status_log` (`new_status=3`, `h.id != 0`)
- Headline metric: `db_repos` = `uniqExactIf(triple, toDate(hsl.created_at,'UTC') >= claim_date - 7)`

SQL artifact: `cloud-measure-org14.sql` (on fleet box / to be committed with plan).

## Current UTC month row (card headline source)
| bucket | deduped | db_repos |
| --- | ---: | ---: |
| 2026-09-01 | 630 | **533** |

UI lock (QG Q2): ≈ **509** This Month under admin-impersonation.

**Delta:** Cloud `db_repos` **533** vs UI ≈ **509** (~24). Do not invent a fix in-plan — file follow-on ACs with this evidence if V1 re-pin confirms UI still ~509. Possible non-bug explanations to check in Implement/Verify: exact UI pin (V1), cache/TTL, description-line vs headline, whether demo was mid-month earlier.

## Adjacent buckets (context only)
Aug 2026: deduped 334 / db_repos 286 · Jul 2026: 307 / 251

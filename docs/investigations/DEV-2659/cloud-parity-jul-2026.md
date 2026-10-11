# DEV-2659 Cloud parity — Jul 2026 claims (READ-ONLY)

Measured: 2026-09-25 ~07:35 CT against ClickHouse Cloud Production via ch-cloud-readonly (no writes).

Window: 2026-07-01 00:00:00 UTC → 2026-07-31 23:59:59 UTC (`ts` 1782864000–1785542399; new path also `event_date` 2026-07-01..2026-07-31). Platform-wide `org=0`. `event_type = 'case-claim'` only.

## Aggregates

| path | all_claims | successful | deduped | read_rows (summary) |
| --- | ---: | ---: | ---: | ---: |
| OLD `zod.public_billing_events` + API keys + insightlpr needles | 15128 | 14288 | 11975 | 46,278,358 |
| NEW `clarity.billing_claim_events_v` + `is_internal = 0` | 15128 | 14288 | 11975 | 24,576 |

## Set diff (successful non-blank VIN triples)

- old − new = 0
- new − old = 0

View probe Jul case-claim: rows 15160 / external 15128 / internal 32 (matches OKF 32-row internal delta).

## Verdict

Exact parity. Jeff hold lifted; QA Ready remains.

## Repos funnel (`LprRepossessionsQuery`) — same window

Hotlist / status-log legs unchanged (`zod.public_hotlist` + `public_hotlist_status_log`, floor 2026-06-24). Only the billing/claims leg differs old vs new.

| path | deduped | db_repos |
| --- | ---: | ---: |
| OLD | 11975 | 9149 |
| NEW | 11975 | 9149 |

Deduped `(forwarder, agent, vin, claim_date)` set-diff: old−new = 0, new−old = 0.

## Combined verdict

Claims aggregates + repos aggregates + both set-diffs: exact parity. Jeff hold remains lifted.

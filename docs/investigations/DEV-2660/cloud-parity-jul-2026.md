# DEV-2660 Cloud parity — Jul 2026 recoveries (READ-ONLY)

Measured: **2026-09-28T14:23:53Z**. Box-side via the fleet RO env. This worker did not re-run Cloud ClickHouse. `SELECT 1` ok. `cloud_touched=true` (read). `cloud_ch_writes=false`.

Packet window (plan #25, not a new clock reading): 2026-07-01 00:00:00 UTC → 2026-07-31 23:59:59 UTC. Platform-wide `org = 0`.

**STOP_SWAP=false.** FINAL on `clarity.hotlist` = **no**. `{floor}` = **KEEP**.

Decisions file: `docs/investigations/DEV-2660/cloud-parity-jul-2026-decisions.json`.

## AC-1 agreement

Status-log status-3 transition vs `clarity.hotlist.first_repossessed_at`.

| | |
| --- | --- |
| Agreement | 100.0% |
| Agree / denominator | 9149 / 9149 |
| `null_fra_triples` | 0 |
| `disagree_date_triples` | 0 |

## AC-3 Jul 2026 `deduped` / `db_repos`

| | Old | Candidate | Δ |
| --- | --- | --- | --- |
| Deduped | 11975 | 11975 | 0 |
| `db_repos` | 9149 | 9149 | 0 |

`stop_swap_from_ac3=false`.

## AC-4 summary header

| | Old | Candidate | Drop |
| --- | --- | --- | --- |
| `read_rows` | 554981738 | 271080491 | 51.15% |
| `read_bytes` | 9777707064 | 6359482618 | 34.96% |
| `memory_usage` | 199785802 | 245335630 | — |

Prose cite: read_bytes −35%, read_rows −51%. Memory is not a drop.

## FINAL on `clarity.hotlist`

| | |
| --- | --- |
| `use_final` | false |
| Reason | db_repos identical; FINAL costs more bytes/memory |

No separate with-FINAL byte table was recorded.

## `{floor}`

| | |
| --- | --- |
| Decision | KEEP |
| Reason | floor-off blows memory ~12.2GB vs ~234MB |

The prefilter stays on the clarity subquery (`toDate(first_repossessed_at, 'UTC') >= {floor}`) because it shrinks the hash build side. It was never a sort-key prune on `public_hotlist_status_log` (`ORDER BY id`).

## m4 min-vs-max residue

| | |
| --- | --- |
| `residue_triples` | 0 |
| `residue_pct_of_old_db_repos` | 0.0 |
| Forecast | 0.187% |

Live residue is 0. The 0.187% figure stays the forecast only.

## m5 MV backfill

| | |
| --- | --- |
| `null_first_repossessed_at` | 0 |
| Method | FINAL and max(FRA) |
| `stop_swap_from_m5` | false |

Naive-join false nulls were discarded. The 0 above is the FINAL / `max(FRA)` count.

## Historical 23% `repo_date` figure

Closed comparison only. Not a `first_repossessed_at` result. `repo_date` disagreed with the status-log transition on **1588 / 6770** counted pairs (**23%**). That column is not the recoveries timestamp.

## Swap

Product PHP reads `clarity.hotlist.first_repossessed_at` with no `FINAL` and with `{floor}` kept. `zod.public_hotlist FINAL` remains the (org, vin) match. No Cloud write was issued from this record.

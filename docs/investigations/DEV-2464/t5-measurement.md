# DEV-2464 t5 — production measurement of the SHIPPED SQL

Run 2026-09-09, read-only against production ClickHouse Cloud as `readonly_user`, reading the
server-side envelope from the `X-ClickHouse-Summary` header. The SQL was **rendered from the class
itself** (reflection on `LprRepossessionsQuery::sql()`) so there is no transcription drift between
what ships and what was measured. This is the node the operator deferred to pre-merge; review round
11 showed deferring it was the wrong call, because all three lanes independently flagged that the
shipped restructure was not the shape any prior measurement covered.

## 1. Correctness — the shipped SQL DOES reproduce the AC

| scope | stage | AC target | shipped SQL |
| --- | --- | --- | --- |
| platform-wide | deduped | 11,975 | **11,975** — exact |
| platform-wide | DB repos | 9,116-9,117 | 9,137 (+20, live-`hotlist` drift) |
| org 165 (largest forwarder) | deduped | 7,230 | **7,230** — exact |
| org 165 | DB repos | 5,355-5,356 | 5,371 (+15, same drift) |

This is with the `insightlpr` exclusion, the `orgs` CTE, and the round-11 `h.id != 0` sentinel fix
all in place — i.e. the code as it actually ships. **AC-1 is now evidenced.** Lane 3's H1 and lane
2's M5 (the docblock claiming a validation the code had not received) are closed by measurement
rather than by argument.

The drift is consistent with every prior reading: 9,117 (2026-08-27 screenshot) → 9,130 (39117,
2026-09-04) → 9,135 (2026-09-08) → 9,137 (today), ~1.3-1.6/day upward, exactly the direction port
rule 4 predicts for a leg that reads live `hotlist`.

## 2. Envelope — the restructure is CHEAPER, and the 13-month span is nearly free

| shape | read_rows | read_bytes | peak memory | wall (median of 3) |
| --- | --- | --- | --- | --- |
| shipped, 1 month | 584,044,321 | 11.33 GB | **2.22 GB** | 2.34s |
| shipped, 13 months (what the card renders) | 584,044,321 | 13.18 GB | **2.79 GB** | 6.00s |
| *prior figure, the non-shipped measured A1* | *664,270,998* | *15.04 GB* | *2.50 GB* | — |

13-month vs 1-month: **1.00x rows, 1.16x bytes, 1.25x peak memory.**

Two lane predictions are refuted:
- Lanes 2 and 3 reasoned the 13-month `hsl` hash build would be ~13x larger. It is **1.25x**. The
  `{floor}` widens the status-log side, but that side is filtered to `new_status = 3` first, so the
  surviving set grows far less than the span.
- Lane 3 argued the restructure might be materially heavier. It is **lighter** on every axis than
  the shape the earlier numbers came from (584M vs 664M rows, 11.33 vs 15.04 GB). Dropping the
  `pairs`/`DISTINCT` reduction did not cost what was feared.

Also confirmed: the org filter still buys nothing — 584,044,321 read_rows is byte-identical for org
165 and platform-wide, because every table is `ORDER BY id`.

## 3. The concurrency risk is REAL, and the 68 GB comparison was the wrong denominator

Worst case stands, with better numbers: 29 entitled orgs cold simultaneously on the 13-month shape
is **29 x 2.79 GB ≈ 81 GB** of ClickHouse-side memory.

Server settings read directly (`system.settings`), and they are the concerning part:

| setting | value | meaning |
| --- | --- | --- |
| `max_memory_usage` | **0** | no per-query memory cap |
| `max_memory_usage_for_user` | **0** | no per-user cap either |
| `max_bytes_in_join` | **0** | no join-spill threshold |
| `max_execution_time` | **0** | server-side; so `clarity_clickhouse`'s 60s `timeout_query` is CLIENT-side only — an abandoned query keeps consuming server resources |
| `join_use_nulls` | **0** | independently confirms the sentinel-zero mechanism behind the round-11 correctness bug |

**Correction to the earlier framing, mine included:** the `host_ram_gb: 68` figure in the ledger is
the production *application* host. This 2.79 GB is ClickHouse *server-side* `memory_usage` on
ClickHouse Cloud, so 68 GB is not the right denominator. The honest statement is: ~81 GB of
CH-side memory in the all-cold case, with **no per-query and no per-user cap** to bound it, against
an instance whose total RAM `readonly_user` cannot read (`system.asynchronous_metrics` is denied).

That is still an unquantified risk, but it is a different one from "exceeds the app host's RAM",
and it should not be reported as the latter.

## 4. What this closes, and what it leaves

**Closed by measurement:** AC-1 evidenced on the shipped SQL; the restructure's envelope known and
better than assumed; the 13-month span shown to be nearly free; the `join_use_nulls = 0` mechanism
confirmed.

**Left open:** the contended p99 is still unmeasured (every figure here is one query at a time), so
the guard constants remain provisional — though they are now derived from the production
`timeout_query` ceiling rather than an observed single-run maximum, which is a sounder basis.
The cross-org concurrency ceiling is unknown because the instance's memory is unreadable from here.
The batch shape (`dc1`) remains the mitigation that would turn N x 11-13 GB into 1 x 11-13 GB.

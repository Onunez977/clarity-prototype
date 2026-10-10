# DEV-2464 q8 — which hotlist table for the recoveries leg

Measured read-only against production ClickHouse Cloud, 2026-09-09, using the
**`X-ClickHouse-Summary` response header** for the server-side envelope (the technique DEV-2466
established — no `READ ON REMOTE` grant needed). Query variants: `measure-q8-variants.py`.
Aggregates and envelopes only, no raw rows.

**How to re-measure.** POST the SQL to the ClickHouse HTTP endpoint and read the
`X-ClickHouse-Summary` response header, which returns a JSON object carrying `read_rows`,
`read_bytes` and `memory_usage`. Any HTTP client works; `clickhouse-client` prints the same figures
on stderr. The throwaway harness used here is deliberately **not committed** — it builds an
`Authorization: Basic` header from `CLICKHOUSE_USERNAME`/`CLICKHOUSE_PASSWORD`, and
`checkpoint.sh`'s PII scan correctly refuses credential-shaped code in the repo. Read the creds
from `chlocal/.env` at runtime instead.

## The three variants

| variant | recoveries leg |
| --- | --- |
| **A0** | backyard-faithful: `zod.public_hotlist FINAL` (`status = 3`) ⋈ `zod.public_hotlist_status_log` (`new_status = 3`), `repo_date = max(created_at)`. No date floor. |
| **A1** | A0 **plus** backyard's own `min(claim_date) − 7` floor on the status log. |
| **B** | `clarity.hotlist FINAL` (`status = 3`), using its denormalized `repo_date` column. **No status-log join at all.** |

## Cost — Jul 2026, platform-wide

| variant | read_rows | read_bytes | peak memory | db_repos |
| --- | --- | --- | --- | --- |
| A0 | 623,895,738 | 15.83 GB | 3.72 GB | 9,137 |
| **A1** | 664,270,998 | 15.04 GB | **2.50 GB** | 9,137 |
| B | **105,672,062** | **5.54 GB** | **1.89 GB** | 9,137 |

Backyard's floor is worth keeping: it does not reduce rows read, but it cuts **peak memory by 33%**
(3.72 GB → 2.50 GB) and produces an identical answer. B is cheaper still on every axis — 5.9x fewer
rows, 2.9x fewer bytes, half the memory.

## Correctness — and why B loses anyway

Swept 5 windows x 5 org scopes, 25 comparisons:

**23 exact matches, 2 mismatches** — both in April, B lower by 2 platform-wide and by 1 for org 165.

Diagnosed with aggregate counters rather than guessed:

| counter | April, platform-wide |
| --- | --- |
| A counts | 6,770 |
| B counts | 6,768 |
| A counts, `(vin, org)` **absent from `clarity.hotlist`** | **2** |
| A counts, B has a NULL `repo_date` | 0 |
| A counts, B's `repo_date` falls outside the 7-day window | 0 |
| **counted pairs where B's `repo_date` differs from A's transition date by >= 1 day** | **1,588** |

Two separate facts, and the second is the decisive one:

1. **`clarity.hotlist` has a small coverage gap.** Two `(vin, org)` pairs that exist in
   `zod.public_hotlist` are missing from the denormalized copy entirely. That is the -2.
2. **`clarity.hotlist.repo_date` is not the same date as the status transition — it differs for
   1,588 of 6,770 counted pairs, 23%.** It happens not to change the answer because
   `>= claim_date - 7` is loose enough to absorb a few days of slack. **B therefore agrees by luck
   of a generous predicate, not by equivalence.** Tighten the window, change the reporting need, or
   let the two dates drift further apart, and B diverges materially.

`repo_date` on `clarity.hotlist` derives from `hotlist_meta.repo_date` — a client-reported date —
whereas backyard's definition is explicitly *our* `hotlist_status_log` transition to `status = 3`.
Different fields, different provenance. The 23% figure is what that difference looks like measured.

## Recommendation: A1

Use the backyard-faithful join, with the floor. The 3x cost is worth paying here:

- The ticket mandates porting the SQL near-verbatim, and port rule 3 already warns that following
  prose over the actual SQL under-counts. Substituting a different date column is a larger
  divergence than the one the ticket explicitly guards against.
- This figure is reconciled against Brek's Repo Report and is billing-adjacent — the same
  `(forwarder OR agent)` axis backyard's `ClarityCommercialFeeSummary` bills from. Definitional
  fidelity outranks a 3x scan here.
- B's agreement is not robust. 23% of its dates are wrong-but-absorbed; that is a latent defect, not
  a saving.

Record B in the Build Record as measured-and-rejected, with the 23% figure, so nobody re-proposes it
as a free optimisation.

## The cost finding that matters more than A-vs-B

**The org filter does not reduce the scan at all.** A1 reads 664,270,998 rows whether it is scoped to
one org or to the whole platform (confirmed here, and matching
`okf/concepts/billing-events.md`'s finding for `billing_events`). Nothing prunes: every table
involved is `ORDER BY id`.

So **per-org computation is the worst possible shape** — N orgs cache-missing means N x 15 GB and
N x 2.5 GB of memory, for a query whose cost is identical to computing every org at once.

That points at a batch shape rather than a per-org one: **one query that emits all orgs in a single
pass** (an `arrayJoin` over `[forwarder_org_id, agent_org_id]` to attribute each claim to both sides,
grouped by org and month), cached as a map — exactly what `ClientRankedMetricsBatch` and
`BatchMonthlySparklineData` already do for client analytics. That turns N x 15 GB into 1 x 15 GB.

This is a design consequence for Phase 3, not a question for the operator: it does not change the
metric, only how many times the same scan is paid for. It also interacts with q13 — a shared batch
map is a different caching object than a per-org `Cache::flexible` key.

## Correcting my own earlier cost claim, again

I told the operator this funnel was "~5s median, ~6x cheaper than the ticket assumes." That was
wall-clock only. The server-side envelope shows A1 reads **15.04 GB and allocates 2.50 GB per
query**. Wall clock hid all of it, and on this shared Cloud instance wall clock is dominated by
contention rather than by the query. **Report `read_rows` / `read_bytes` / `memory_usage` from the
summary header; treat wall clock as the least informative number available.** Third time on this
ticket that wall clock misled me.

---
type: DomainEntity
title: Billing Events
description: The per-API-call billing ledger — Backyard-owned in PostgreSQL, read by Clarity only through the ClickHouse CDC mirror zod.public_billing_events. Covers event_type values, the additional JSON payload, why nothing prunes (ORDER BY id), and the critical fact that this is the ONLY zod.public_* mirror whose ReplacingMergeTree omits _peerdb_is_deleted, so FINAL does not drop its tombstones.
resource: app/Clickhouse/Queries/UniqueLprCaseClaimsQuery.php
tags: [domain, billing, clickhouse, cdc, peerdb, case-claim]
timestamp: 2026-09-09T00:00:00Z
---

# Billing Events

The **per-API-call billing ledger**: one row each time an org consumes a billable InsightLPR
service through an API key. Read [Data Architecture](data-architecture.md) first — this entity
exists on both sides of the split and Clarity only ever reads one of them.

## Where it lives, and who owns it

| | |
| --- | --- |
| **PostgreSQL** | `zod.billing_events` (the `public` schema of the `zod` database) — **Backyard-owned**. Backyard writes it; Clarity has **no Eloquent model** for it and never queries the Postgres side. |
| **ClickHouse** | `zod.public_billing_events` — the PeerDB CDC mirror. **This is Clarity's only read path.** |

Because the writer is another application, treat the columns as an external contract: a shape
change lands in Clarity via CDC without a migration in this repo. See
[Repositories](repositories.md) for the cross-repo registry.

## Mirror schema

```sql
CREATE TABLE zod.public_billing_events (
  `id` Int64, `api_key_id` Int32, `success` Bool, `event_type` String,
  `additional` String, `ts` Int64,
  `_peerdb_synced_at` DateTime64(9), `_peerdb_is_deleted` UInt8, `_peerdb_version` UInt64
) ENGINE = ReplacingMergeTree(_peerdb_version)
PRIMARY KEY id ORDER BY id;
```

- **`ts` is an integer epoch**, not a datetime. Window with an inclusive `BETWEEN` on epoch
  integers; derive the bounds from a timezone-anchored timestamp rather than by hand (hand-computed
  UTC month bounds have been silently two days off).
- **`additional` is a JSON string**, not a typed column. Extract with `JSONExtract*`.
- **No org column.** The paying org is reached through `api_key_id` → `zod.public_api_keys.org_id`.
- **No indexes, `ORDER BY id`.** See "nothing prunes" below.

## `event_type` values

From `InsightLPR\BlackAdam\Enum\BillingEventType` (the discriminator column is **`event_type`**, not
`type`):

| value | meaning |
| --- | --- |
| `case-claim` | a repo case claim — the LPR funnel |
| `ls-claim` | an LS case claim (separate product; off by default in the claims funnel) |
| `v2p` | VIN-to-plate lookup |
| `ins-lookup` / `ins-peek` | insurance lookup / peek |
| `smartass` | SmartAss |
| `claritypro` | Clarity Pro |

Measured in production 2026-09-08: **≈38.0M** rows total, of which **365,497** are `case-claim`.

## The `additional` payload for `case-claim`

| key | meaning |
| --- | --- |
| `claimed_by_org_id` | the **agent** org — the claiming agency |
| `vin` | the vehicle VIN (normalise with `upper(trimBoth(...))`; blanks exist) |
| `hotlist_id` | the claimed order |

The **forwarder** org is *not* in the payload — it is the API key's owning org
(`public_api_keys.org_id`). Any per-org claims metric must therefore count an org on **either**
side: `forwarder_org_id = ? OR agent_org_id = ?`.

## Nothing prunes — a window filter does not reduce the scan

`ORDER BY id` with no skip index over `ts` means an `event_type`/`ts` predicate cannot narrow the
read. Measured against production with the `X-ClickHouse-Summary` header:

- **40,046,830 `read_rows` / 1.83 GB** for a one-month window — and the **same** figure for a
  3-month and a 13-month window.
- The same figure again when scoped to a **single org**, verified across 11 orgs and 22 interleaved
  runs at **0.00% spread** — including orgs whose answer is `0`.

Two consequences for anything built on this table:

- **A widening time window is free; an org filter is not a saving.** Sizing a feature "per org" and
  multiplying is the correct arithmetic: N orgs cache-missing = N full scans.
- **Compute every aggregate in one pass.** Each additional reference to a CTE over this table is
  another full scan: three references measured **119,848,324 `read_rows` / 5.50 GB** versus
  **39,949,441 / 1.83 GB** for a single-pass `count()` / `countIf()` / `uniqExactIf()` — exactly 3.0×.

Wall clock is dominated by ClickHouse Cloud contention, not by the query: the same query, org and
window measured a 0.31 s median in a quiet window and a **3.6 s median with a 9.3 s max** under
contention. Size any lock or timeout against the tail, not the quiet median.

## CRITICAL: `FINAL` does **not** drop this table's tombstones

`zod.public_billing_events` is declared `ReplacingMergeTree(_peerdb_version)` — **one argument**.
Every other `zod.public_*` mirror (24 of 25, including `public_orgs`, `public_api_keys`,
`public_hotlist`, `public_scans`) is `ReplacingMergeTree(_peerdb_version, _peerdb_is_deleted)`.

The second argument is what makes `FINAL` treat `_peerdb_is_deleted = 1` rows as deletions. So:

- On the other 24 mirrors, `FINAL` deduplicates **and** removes tombstones.
- On **`billing_events` alone**, `FINAL` deduplicates but **keeps tombstoned rows**. An explicit
  `WHERE _peerdb_is_deleted = 0` is **mandatory** and is *not* interchangeable with `FINAL`.

This asymmetry is the mechanism behind a long-standing confusion in this repo about whether `FINAL`
drops tombstones — the answer is table-dependent, and it depends on this one declaration. See
PG-028 in [Platform Gotchas](platform-gotchas.md).

Rows are also **immutable once written** in practice, so unlike a mutable table there is no drift
between two readings of the same historical window — a figure reproduced today will reproduce
tomorrow.

## Internal-org exclusion

Claims metrics exclude InsightLPR's own orgs by matching the needle `insightlpr` against the org
name with non-alphanumerics stripped:
`position(lower(replaceRegexpAll(coalesce(name,''), '[^a-zA-Z0-9]', '')), 'insightlpr') = 0`.

Two rules that have caused a real miscount when confused:

- **Only `insightlpr` filters.** A `titlemax` needle exists in Backyard's `RepoReport` but drives a
  **display flag only** (`isExternalRepoSystem()` → `decorateRow()`); nothing filters on it.
  Collapsing the two into one filter is a measured **−2.2% to −2.8%** error across the funnel stages.
- The exclusion self-checks: for Jul 2026 it moves all-claims from 15,160 to **15,128** — a 32-row
  delta — while leaving `successful` and `deduped` unchanged, i.e. those 32 are all failures.

Because the exclusion joins through `orgs`, and `FINAL` on `orgs` **does** drop tombstones
(2-arg form), a soft-deleted internal org would fall out of the join, `coalesce()` would yield `''`,
the needle would miss, and its claims would silently re-enter the count. Unreachable today (the
needle matches 4 orgs, all live) but it fails **open**, not closed.

## The claims funnel

The three stages, as ported from Backyard's `RepoReport`:

| stage | definition |
| --- | --- |
| all claims | every `case-claim` row in the window, failures included |
| successful | `countIf(success)` |
| **deduped** | `uniqExactIf((forwarder_org_id, agent_org_id, vin), success AND vin != '')` — the billed count |

Dedup is on the **triple**, not on the VIN alone: the same VIN claimed through a different
agent/forwarder pairing is a genuinely separate claim. Use `uniqExact`, not `uniq` — `uniq` is
HyperLogLog and approximate, and these figures are reconciled against a report. Blank VINs are
excluded from the dedup because a blank cannot be deduplicated and would collapse every blank into
one row. Follow the SQL rather than `RepoReport`'s docblock prose, which describes
"distinct (vin, agent)" and under-counts when one agent claims the same VIN through two forwarders.

Reference figures (Jul 2026 UTC, `ls-claim` off, platform-wide): **15,128 / 14,288 / 11,975**.

## See also

- [Company Metrics Dashboard](company-metrics-dashboard.md) — the card that surfaces `deduped`.
- [ClickHouse Query Patterns](clickhouse-query-patterns.md) · [Data Architecture](data-architecture.md)
- [Organization](organization.md) · [Repositories](repositories.md)
- [Platform Gotchas](platform-gotchas.md) — PG-002 (missing `FINAL`), PG-028 (this table's 1-arg engine).

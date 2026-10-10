# DEV-2463 — the SHIPPED class verified against production

**2026-09-08.** Read-only against production ClickHouse Cloud as `readonly_user`.

## Why this exists

The main acceptance criterion is *"reproduces the corresponding funnel stage of Brek's Repo
Report for the same window and org set."* That number is **not reproducible in CI** — it needs
38M production rows. CI can only pin the *rules*, which it does (12 tests in
`tests/Feature/Clickhouse/UniqueLprCaseClaimsQueryTest.php`).

So the AC is closed here instead, and deliberately **not** by re-running the hand-written
`measure-claims-card.sql` that prep validated. That would only prove the *design* was right,
not that the shipped code matches it. Instead the SQL was rendered **out of the shipped class
itself** by reflection on `UniqueLprCaseClaimsQuery::sql()`, with only the placeholder
substitution the class's own `run()` performs:

```php
$m = (new ReflectionClass(UniqueLprCaseClaimsQuery::class))->getMethod('sql');
$m->setAccessible(true);
strtr($m->invoke(null, false), ['{db}'=>'zod','{org}'=>'0','{f}'=>…,'{t}'=>…,'{event_type}'=>"'case-claim'"]);
```

Driver: `verify-shipped-against-prod.py`.

## Result — all six target figures EXACT

Jul 2026 (UTC calendar month), `ls-claim` off:

| scope | stage | ticket target | shipped class |
| --- | --- | --- | --- |
| platform-wide | all claims | 15,128 | **15,128** |
| platform-wide | successful | 14,288 | **14,288** |
| platform-wide | deduped | 11,975 | **11,975** |
| largest forwarder (org 165) | all claims | 9,340 | **9,340** |
| largest forwarder (org 165) | successful | 9,262 | **9,262** |
| largest forwarder (org 165) | deduped | 7,230 | **7,230** |

Note the org-scoped run returns the same figures as the platform-wide one for the largest
forwarder's own row, which is the expected behaviour of the either-side org axis.

## Server-side envelope of the shipped query

| metric | platform-wide | org-scoped |
| --- | --- | --- |
| `read_rows` | 39,967,136 | 39,967,136 |
| `read_bytes` | 1.83 GB | 1.83 GB |
| peak `memory_usage` | 403 MB | 399 MB |
| wall clock | 5.81s | 7.01s |

`read_rows` is identical whether the query is org-scoped or platform-wide — confirming again
that **nothing prunes** (`zod.public_billing_events` is `ORDER BY id`), so the org predicate
reduces only the surviving row count, not the scan.

These wall-clock figures (5.8–7.0s) sit in the **contended** band, consistent with
`perf-measurement.md` §5's corrected picture (0.3s quiet → 9.3s contended) rather than the
0.31s quiet-window median. They are the reason the `Cache::flexible([3600, 86400])` rule is
load-bearing.

## What this does and does not prove

**Does:** the code that will ship produces the ticket's numbers, on production data, with the
`insightlpr` exclusion and the 3-column dedup key applied as written.

**Does not:** pin a snapshot. No point-in-time freeze was taken, so this is
*consistent-with* rather than *proven-identical-to* Brek's 2026-08-27 screenshot. That
caveat is weaker here than it was for DEV-2464's DB-repos leg, because `billing_events` rows
are immutable once written — there is no live-drift mechanism on this card's inputs.

---

## Re-verification after review round 2 (2026-09-08)

All three RN=2 lanes correctly flagged that this artifact certified a pre-round-2 `sql()` body.
Re-run after the round-2 changes, SQL again rendered out of the shipped class by reflection:

| scope | all / successful / deduped | verdict |
| --- | --- | --- |
| platform-wide | 15,128 / 14,288 / 11,975 | **exact** |
| largest forwarder (org 165) | 9,340 / 9,262 / 7,230 | **exact** |

`read_rows` 40,004,964 (up from 39,967,136 — four days of new `billing_events` on an append-only
table, not a query change); `read_bytes` 1.83 GB; peak `memory_usage` 392–422 MB; wall 0.59–3.48s.

Note the round-2 `orgs`-CTE edit was **withdrawn** before this run — see `review-2-findings.md`.
This artifact now certifies the code as it stands.

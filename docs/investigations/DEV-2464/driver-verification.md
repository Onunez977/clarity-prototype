# DEV-2464 — driver's own verification of two consequential prep claims

Verified directly by the driver (not delegated) because both feed the question gate.

## 1. The Jira comment's "add a new FeatureEnum case" instruction is STALE — the case already shipped

Comment 39129 says: *"**Pennant gate:** a new `FeatureEnum` case at `CLARITY_PRO` +
`CLARITY_PRO_PLUS`, matching `MISSED_OPPORTUNITY` and `CLIENT_ANALYTICS`. Confirm the tier only."*

Code on `origin/main` already has it:

```
app/Enums/FeatureEnum.php:91   case COMPANY_METRICS = 'platform_repo_pro_company_metrics';
app/Enums/ProTierEnum.php:62   FeatureEnum::COMPANY_METRICS,   (inside self::CLARITY_PRO)
app/Enums/ProTierEnum.php:79   FeatureEnum::COMPANY_METRICS,   (inside self::CLARITY_PRO_PLUS)
app/Providers/PennantServiceProvider.php:48
    Feature::define(FeatureEnum::COMPANY_METRICS->slug(),
        fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::COMPANY_METRICS));
```

So the tier is ALREADY exactly what the comment asked to confirm — `CLARITY_PRO` and
`CLARITY_PRO_PLUS`, and it is NOT in `BASIC` or `PRO_LITE`. There is **one dashboard-wide feature**,
not one feature per card. **Answered from code; not a human question.** A build session must NOT add a
second case — that would create a card gated independently of its own dashboard.

## 2. The ClickHouse schema-dump gap is REAL but narrower than the fast pass reported

Per-table verdict against `database/schema/clickhouse-schema.sql` (the CI dump — 66 `CREATE`s; this
is the file `.cursor/start.sh` loads):

| table | in CI dump | engine / sort key |
| --- | --- | --- |
| `zod.public_billing_events` | **ABSENT** | — |
| `zod.public_api_keys` | present (line 49) | `ReplacingMergeTree(_peerdb_version, _peerdb_is_deleted)` `ORDER BY id` |
| `zod.public_hotlist` | present (line 56) | same, `ORDER BY id` |
| `zod.public_hotlist_status_log` | present (line 63) | same, `ORDER BY id` |
| `zod.public_orgs` | present (line 67) | same, `ORDER BY id` |

Correction to the fast pass: it reported the gap against BOTH dumps. `database/schema/local-clickhouse-schema.sql`
carries only **8** `CREATE`s and **no `zod.*` tables at all** — it is a partial local fixture, not a
second full dump, so "absent from the local dump" is true of all five and is not evidence of a gap.
**The single real gap is `zod.public_billing_events` missing from the CI dump.**

Two consequences, both real:
- A CI test that reads `zod.public_billing_events` cannot run — the table will not exist.
- `tests/Concerns/RequiresClickhouse.php` truncates `zod.public_hotlist_status_log`,
  `zod.public_address`, `zod.public_hotlist_address` and `zod.kit_last_scan`, but NOT
  `zod.public_hotlist`, `zod.public_api_keys`, `zod.public_orgs`, or `zod.public_billing_events`.
  Any test seeding those four leaks rows into every later test class in the same run — and the
  literal `clarity`/`zod` databases are SHARED across worktrees, so it leaks across runs too.

## 3. Every table this card touches is `ReplacingMergeTree ... ORDER BY id`

Read off the DDL above. Two implications worth carrying into the plan:
- **No pruning.** None of `org_id`, `vin`, or `hotlist_id` is in a sort key, so the org-scoped join
  must scan. This is the mechanical reason the funnel costs ~32s cold, and it means the caching rule
  is load-bearing exactly as the ticket says.
- **Duplicate rows are possible without `FINAL`.** `ReplacingMergeTree` collapses only on merge, so
  an unmerged part can yield two rows for one `id`. A ported count that does not account for this can
  over-count. Whether the reproduction run behind comment 39117 used `FINAL` is not recorded on the
  ticket — that is a question, not an assumption.

---

## 4. The grid slot is NOT blocked on DEV-2463 — candidate 3's blocker is overstated

`CompanyMetricsOverview::cards()`'s docblock IS the declared source of truth (`Dashboard.php:82-90`
defers to it). It gives the mockup as a 4x4 grid, `[bracketed]` = not built:

```
row 1 | Scan Count            | Total Repossessions    | [Unique LPR Case Claims]      | [LPR Repossessions]
row 2 | [Unique Scan Counts]  | [Unique Scans to Repo] | [Unique Scans to Case Claims] | [Unique Scans to LPR Repossession]
row 3 | [Unique Scans Ldrbrd] | Staging Live Hit Ldrbrd| Missed Opportunities          | LPR Recovery Leaderboard
row 4 | Live Hits             | Direct Historical Hits | Staging Live Hits             | Staging Historical Hits
```

So **this card is row 1 col 4**, and DEV-2463's `[Unique LPR Case Claims]` is row 1 col 3.

The array today (9 entries):
```
0 scan-count               row1c1
1 recovery-counts          row1c2  ("Total Repossessions")
2 staging-live-hit-leader  row3c2
3 missed-opportunities     row3c3
4 recovery-leader          row3c4
5 live-hits                row4c1
6 historical-hits          row4c2
7 staging-live-hits        row4c3
8 staging-historical-hits  row4c4
```

`lpr-repossessions` therefore inserts at **index 2** — after `recovery-counts`, before
`staging-live-hit-leader`. That position is unambiguous and does not depend on DEV-2463: when
DEV-2463 later lands, `unique-lpr-case-claims` inserts at index 2 and pushes this card to index 3.
That is EXACTLY the documented design — the docblock says *"Each sibling ticket inserts its card at
its exact mockup position, between the existing entries"*, and the foundation *"paid the reorder cost
ONCE so that each later ticket drops in without a second one."*

**Correction:** candidate 3 called this a hard blocker ("either inserting at the WRONG slot ... or
leaving a placeholder gap — neither is currently specified"). It is specified, and the insert is
well-defined. **Drop it as a blocker.** The DEV-2463 dependency that survives is about the shared
CTE (duplicate-port risk), not the slot.

### Side effect the plan must check, not assume
The same docblock warns: *"AT 9 CARDS THEY DO NOT RENDER AS ONE ROW"*, with the rule
`count(preceding built cards) % columns == 0`. Today 5 cards precede the four-hit block. A 10th card
inserted at index 2 makes it **6** preceding, and at `lg` the grid is 6 columns — so adding this card
may RE-ALIGN the hit row that DEV-2461 de-aligned. The docblock's own claim is that it *"re-aligns at
12"*. One of those two is wrong. Verify by measuring, do not reason about it, and update the docblock
to whatever measurement shows.

## 5. A third hardcoded-count site exists, but only bites if a new FeatureEnum case is added

`tests/Unit/Enums/FeatureEnumTest.php:74` asserts `assertCount(18, $values)` over the enum's slugs,
and the expected list already contains `'platform_repo_pro_company_metrics'`. Since finding 1 says no
new case is needed, **this file needs no edit** — but it is exactly the site that would fail silently
late if someone did add one.

## 6. The sparkline-vs-one-query tension is real, and the precedent exists

Verified present: `app/Clickhouse/Queries/HitStatsBatchQuery.php` and
`app/Filament/Widgets/Dashboard/Concerns/SharedHitStatsData.php`. So this repo DOES have the
one-round-trip-covering-many-months pattern the four hit cards' sparklines use. It is the right place
to look for the sparkline design — but it does not exist for this funnel domain, and a month-bucketed
`GROUP BY` over the same 389M-row status-log join is unmeasured. That makes it a design question, not
a copy-paste.

---

## 7. DECISIVE: the ticket's ClickHouse row counts are RAW counts — so its "the port reproduces the AC" evidence is suspect

Candidate 1 flagged that the ticket's 5 port rules never mention `FINAL` / `_peerdb_is_deleted = 0`,
and that it is unrecorded whether the comment-39117 reproduction used them. Candidate 2 argued the
status log is append-only and needs neither. They disagreed, so I measured it.

Read-only probes against production ClickHouse Cloud via chlocal's own
`tools/compare.py` (`load_env` + `run_ch_query`) as `readonly_user` — bare `count()`s composed
locally, no caller-supplied SQL, run 2026-09-08:

| table | RAW | RAW, not-deleted | `FINAL` + not-deleted | ticket's figure (39117) |
| --- | --- | --- | --- | --- |
| `zod.public_hotlist` | 154,553,066 | 148,369,106 | **136,362,585** | 156,096,149 |
| `zod.public_hotlist_status_log` | 389,287,068 | — | **389,287,068** | 388,885,025 |
| `zod.public_billing_events` | 38,018,542 | — | **38,018,542** | — |

### Three conclusions

**a. `zod.public_hotlist` genuinely needs `FINAL` + the soft-delete filter.** Raw over-counts by
**18,190,481 rows — 13.3%**. Roughly 6.18M of that is soft-deleted rows and ~12.0M is unmerged
`ReplacingMergeTree` duplicates. Candidate 1's concern is real and material, and `hotlist` is exactly
the table the recoveries leg joins on `status = 3`.

**b. `hotlist_status_log` and `billing_events` need neither, today.** Their raw and
`FINAL`-plus-filter counts are byte-identical, so candidate 2 was right about the status log and
candidate 1's "unconditional" framing was over-broad. Worth knowing: `FINAL` on the status log still
cost **10x** wall clock for zero change (231ms → 2,488ms). That is a real cost to not pay. It is a
current-state observation, not a guarantee — a future soft-delete would change it silently.

**c. The ticket's `hotlist` figure is on the RAW lineage, not the `FINAL` one.** 156,096,149 sits
beside today's raw 154,553,066, and is **12.6% above** the `FINAL` count of 136,362,585. It being
*higher* than today's raw is itself consistent with a raw count: a `ReplacingMergeTree` raw count
FALLS as background merges collapse duplicates, so an earlier raw reading is legitimately larger.
Nothing about 156.1M is consistent with a `FINAL` count.

### Why this matters more than a stale statistic

The reproduction behind comment 39117 reported **DB repos 9,130** against a Postgres target of
**9,116-9,117** — i.e. **+14 high** — and the ticket attributes that to benign live-`hotlist` drift
(its own port rule 4). But an un-`FINAL`'d join on `hotlist` would ALSO read high, by counting
duplicate and soft-deleted hotlist rows. Both explanations fit +14, and they mean opposite things:
one is expected drift, the other is a real defect in the port that validated the route.

I could not settle which, because the reproduction's SQL is not recorded on the ticket — only its
results. **This is a question for the gate, not something to assume either way.** The way to close
it is to re-run the funnel twice, once with and once without `FINAL` + `_peerdb_is_deleted = 0` on
the `hotlist` leg, and see which lands on 9,116-9,117.

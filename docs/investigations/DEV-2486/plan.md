# DEV-2486 — Plan (Phase 3)

**Route:** hotfix, base `origin/deploy` · **Files:** 10 · **Nodes:** 5 · **UAT:** yes (visible admin page change)

Re-key `/app/gl-kits` from one row per **Kit** (a login identity) to one row per **GL**
(a physical camera), sourcing GL identity from the ClickHouse-only, MV-fed
`zod.aggregate_kit_camera_name` scoped by a PostgreSQL-derived `kit_id` list.

## DAG

```
t1  CH substrate (dump line + truncation + generator note)   [BLAST RADIUS]
 └─ t2  OrgKitCameraNamesQuery + unit test
     └─ t3  GlKitsResource ->records() re-key + GlKitsResourceTest rework
         ├─ t4  GlKitsResourceAccessTest narrowed to CH-free guarantees
         └─ t5  Playwright column contract
```

`t4` and `t5` are the only parallel pair; their `target_files` are disjoint.
Nothing that runs a ClickHouse test can pass before **t1** lands — the driving table is
absent from the dump that CI and `clickhouse-qa` replay, so `t2` and `t3` would error on a
missing relation rather than fail for the right reason.

| id | title | files | test filter | depends on |
|----|-------|-------|-------------|------------|
| t1 | CH substrate for `zod.aggregate_kit_camera_name` | 3 | *(none — enables the others)* | — |
| t2 | `OrgKitCameraNamesQuery` | 2 | `OrgKitCameraNamesQueryTest` | t1 |
| t3 | `GlKitsResource` re-key | 2 | `GlKitsResourceTest` | t2 |
| t4 | Access test narrowed (CH-free) | 1 | `GlKitsResourceAccessTest` | t3 |
| t5 | Playwright column contract | 2 | *(none — not wired into CI)* | t3 |

## Blast radius — t1

`database/schema/clickhouse-schema.sql` is a **CI-wide artifact: a malformed line fails
dump load and takes the entire ClickHouse CI job down**, not just this ticket's tests.

This node is **already human-approved** via prep-context question 6's recorded answer —
append one translated DDL line and record the table with the generator. Do not invent a
new approval requirement.

The engine must be `ReplacingMergeTree()`, never production's `SharedReplacingMergeTree`:
the OSS `clickhouse-server:26.2` CI image has no `Shared*` engines at all. The dump header
(`database/schema/clickhouse-schema.sql:1-5`) states the translation rule and the body
carries zero `Shared*` engines across 62 tables.

## Settled decisions carried into the diffs

- Row source is Filament `->records()` array records, per `ClientTable.php:138-141` / `:320-349`.
- Org scoping comes **only** from `glKits($orgId)` (PostgreSQL). `zod.aggregate_kit_camera_name`
  is exactly 2 columns and has no `org_id`; `zod.public_kits` is never queried for scoping.
- Reporting rank ascending: `Reporting(0) < Not Reporting(1) < Unknown(2)`. Mixed
  Not-Reporting + Unknown with none reporting renders **Not Reporting**. `Unknown` is
  reserved for "nothing was checked".
- GL column stays searchable; Company Owner stays as a column.
- **A ClickHouse GL-discovery failure renders ZERO rows** (Q1, accepted trade-off).
  `reportClickhouseFailure()` is kept so the outage still reaches error tracking. No
  table-level "unavailable" empty state — explicitly rejected. This is a resilience
  regression versus today and **must appear in the PR body and the Build Record.**
- `once()`-per-`$orgId` memo idiom preserved, including for the new GL map.
- Comments/docblocks stay minimal — adapt the existing load-bearing "why" notes, do not
  multiply them.
- `ListGlKits.php` is **not** touched — prep concluded no change is needed and the plan agrees.
- No `okf/` edits (they land only after approval).

## Corrections to prep-context (details in each node's `intended_diffs`)

1. **`->recordAction(null)->recordUrl(null)` is mandatory** and prep did not mention it.
   `ListRecords::makeTable()` installs `Model`-typed `recordAction`/`recordUrl` closures
   (`vendor/filament/filament/src/Resources/Pages/ListRecords.php:129-160`) which
   `index.blade.php:986-988` calls per rendered row; Filament injects by parameter name, so
   an array record hits a `Model` parameter and throws a `TypeError` on every row.
   `ClientTable` never hit this because it is a widget.
2. **`Table::getModel()` does not become null.** `ListRecords` still registers
   `->query(fn () => $this->getTableQuery())`, and `getQuery()` never consults `hasQuery()`
   (`vendor/filament/tables/src/Table/Concerns/HasQuery.php:67-72`). `getEloquentQuery()`
   therefore stays live code — which is what makes t4's assertions meaningful.
3. **`.claude/commands/chlocal.md` has no table list.** It transforms whatever is in
   `../chlocal/migrations/cloud_export/cloud_zod_schema.sql`; the table is missing because
   the upstream chlocal export lacks it. t1 adds a documented "manually maintained
   additions" note instead of editing a list that does not exist.
4. **Do not widen `canEdit`/`canDelete` to `Model|array`.** They are only reachable through
   `ListRecords`' `recordUrl` loop, which requires a `view`/`edit` page; this resource
   registers only `index`.
5. **A `last_scan`-only outage is no longer reachable** by connection sabotage: GL discovery
   and `zod.kit_last_scan` share the `public_clickhouse` connection. AC6's per-lookup
   `Unknown`-on-a-rendered-row is covered by a `clarity_clickhouse`-only outage (reporting
   `Unknown`); the old both-connections test becomes the Q1 zero-rows test.

## Buggy-site coverage

All three `buggy` sites from `prep-context.exhaustiveness.sites` are covered; `excluded_sites`
is empty.

| site | node |
|------|------|
| `app/Filament/Resources/GlKitsResource.php` | t3 |
| `tests/e2e/helpers/gl-kits.ts` | t5 |
| `tests/e2e/gl-kits.spec.ts` | t5 |

## TDD contract per node

| node | pinned by | red-before-green |
|------|-----------|------------------|
| t1 | none of its own | it is what lets t2/t3 fail for the right reason instead of erroring on a missing relation |
| t2 | `OrgKitCameraNamesQueryTest` | write the test file first; red on class-not-found |
| t3 | `GlKitsResourceTest` | rework the test file first; the GL-keyed assertions go red against the current kit-keyed page |
| t4 | `GlKitsResourceAccessTest` | the new query-level assertions go red before t3 adds the `glKitsQuery` seam |
| t5 | no PHPUnit pin (Playwright is not in CI) | read-through against the shipped column set |

## Test-run notes

- Never run the suite automatically. When asked: `.env.testing`, single file or filter.
- Local full-suite runs need `php -d memory_limit=2G vendor/bin/phpunit` (or `PHPRC`) —
  `php artisan test` OOMs during discovery at 128M and still exits 0 (the OOM-during-discovery test runner issue). Expect
  ~8 pre-existing failures; check the base commit before blaming this diff.
- `RequiresClickhouse` truncates hardcoded `clarity.*` / `zod.*` literals, so local
  ClickHouse databases must be named literally `clarity` and `zod`, and teardown must
  never drop them.
- `composer format` (Pint) before finishing.

## QA anchors

- Org 1143 → ~49 rows (measured), not 272.
- **org_id 1 (InsightLPR) is the real worst case: 524 distinct GLs from 239 kits** — 10x the
  ticket's anchor. Use it to stress sort and pagination.
- Behavior change to state in the PR: a `kit_type=1` kit that has **never scanned** now
  disappears entirely (GL identity only exists once a scan recorded that `camera_name`),
  where today it shows a Never / Not Reporting placeholder row.

<!-- ticket-status -->
**Ticket:** [DEV-2660](https://insightlpr-team.atlassian.net/browse/DEV-2660)
**Phase:** Implement done
**Done:** intake · Ultra-Prep · Ultra-Plan · Provision · Implement
**Next:** Review
**Gate:** CLEAN
**Open questions:** none. #25 is green. STOP_SWAP=false. FINAL on clarity.hotlist is off. `{floor}` stays KEEP.
**Branch path:** main→main (First Glance **42078**)
**PR:** #470 **DRAFT**
**Provisioned:** true — fleet Path A site :18085 · PG :15437 zod_dev_2660 · Docker CH :18128 clarity_dev_2660 (ledger docs/investigations/DEV-2660/qa-prep-ledger.json). Mac Herd refused. `cloud_touched=true` (read). `cloud_ch_writes=false`. No DSN share with the other live Path A twin.
**{floor}:** KEEP. Floor-off blows memory ~12.2GB vs ~234MB. −7 predicate stays.
**#18:** real fleet Docker CH required for Agent QA/Verify. Fleet not torn down.
**#25:** GREEN at 2026-09-28T14:23:53Z, box-side fleet RO measure (not this worker). AC-1 100% (9149/9149). AC-3 deduped 11975/11975, db_repos 9149/9149 (Δ0). AC-4 read_bytes −35% (34.96), read_rows −51% (51.15). m4 residue_triples=0. m5 null_first_repossessed_at=0 (FINAL and max(FRA); naive-join false nulls discarded). Historical repo_date 23% (1588/6770) is the closed comparison only.
**Ship:** Recoveries leg reads clarity.hotlist.first_repossessed_at (min()). public_hotlist FINAL (org, vin) match kept. No FINAL on clarity.hotlist. Product tip `255107c5b583e7f1b44c64bbc625281a0b8764aa`.
**Assignees:** Jira Dalton McCleery (unchanged). GitHub DaltonMcCleery.
<!-- /ticket-status -->

# DEV-2660 journal

## Ultra-Prep (2026-09-28T13:41:46Z)

Phase **prep**. Gate **CLEAN**. Provisioned **false**. No product PHP. No `okf/` write. No ClickHouse statement executed. No fleet ledger. No DSN.

Live Jira re-GET: [DEV-2660](https://insightlpr-team.atlassian.net/browse/DEV-2660) is To Do, Story, Clarity, its parent epic. Both blocked-by links (the Cloud first-timestamp apply, and the hotlist status-change timestamp backfill) are Done. Label `Carryover_Sprint41`. Sprint field also lists Sprint 42. Status left To Do.

First Glance comment **42078** (2026-09-28 ~8:32 CT) supersedes **42077**. Branch path is **main→main**. Monday cutover is live. Verdict on that comment: Ready → Ultra-Prep. Hard stop cleared because the Cloud apply and backfill of the first-timestamp columns landed. This prep does not re-query Cloud to re-prove that apply.

Base `origin/main` `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`.

### Recoveries leg today

`app/Clickhouse/Queries/LprRepossessionsQuery.php` still implements variant A1.

- Lines 200–203: `zod.public_hotlist FINAL`, `_peerdb_is_deleted = 0`, `status = 3`, match `h.org_id = d.agent_org_id AND h.vin = d.vin`, guard `d.agent_org_id != 0`. `h.vin` is un-normalised.
- Lines 211–215: `zod.public_hotlist_status_log`, `_peerdb_is_deleted = 0`, `new_status = 3`, `toDate(created_at, 'UTC') >= {floor}`, no `FINAL`, guard `h.id != 0`.
- Lines 173–174: `db_repos` is `uniqExactIf` on `toDate(hsl.created_at, 'UTC') >= d.claim_date - 7`.
- Line 147: `{floor}` is the UTC window start minus 7 days. Docblock lines 45–50 say that bound is looser than `min(claim_date) - 7` and exists so the status log does not need a second billing pass.
- Lines 52–57: variant B (`clarity.hotlist.repo_date`) is still recorded as measured-and-rejected (23%).
- Lines 15–20: the class does not extend `ClickhouseQuery`.

Sentinel fixtures already exist in `tests/Feature/Clickhouse/LprRepossessionsQueryTest.php` (agent org 0 at line 187, `hotlist_id = 0` at line 225). `tests/Unit/Clickhouse/BillingClaimDedupTest.php` lines 86–90 still require the status-log string and the `2026-06-24` floor literal. Those tests change with the swap, not in this run.

### Schema on this tip

`database/schema/clickhouse-schema.sql`:

- Line 24, `clarity.hotlist`: `first_repossessed_at` and `first_declined_at` are `Nullable(DateTime64(6))`. Engine is `ReplacingMergeTree`.
- Line 103, `clarity.hotlist_incremental_mv`: `hsl_deduped` sets `first_repossessed_at` to `minIf(toNullable(created_at), new_status = 3)` and `first_declined_at` to `minIf(..., new_status = 4)`, from `zod.public_hotlist_status_log` with `_peerdb_is_deleted = 0` and no `FINAL`.

`app/Enums/HotlistStatusEnum.php` titles status 3 Repossessed and status 4 ClosedPositive. The dump column for status 4 is `first_declined_at`. Recoveries reads status 3. `first_closed_positive_at` is not a dump column.

### Locked shape for Ultra-Plan

Replace the status-log join only. Read `first_repossessed_at`. Keep the `public_hotlist FINAL` match, the un-normalised VIN, both sentinels on the new join key, the class off `ClickhouseQuery`, and the −7 predicate on purpose. Adopt `min()` with the comment-42078 residue of 0.187% versus the current any/max reading. `{floor}` is keep-or-drop with a written reason once the log scan is gone. Do not touch `hotlist_status_aggregate`. Do not reopen the billing-leg change.

Other status-log reads stay: `CohortClosedQuery` and `CohortDeclinedQuery` already filter `_peerdb_is_deleted = 0` and do not use `FINAL`.

### Wiki

Read `okf/index.md`, `clickhouse-query-patterns.md`, `billing-events.md`, `company-metrics-dashboard.md`, `platform-gotchas.md` (PG-030), and `okf/models/clickhouse/hotlist.md`.

Gaps marked UNPROVEN, not filled: the hotlist model page omits the `first_*_at` columns and still says `SharedReplacingMergeTree`; `billing-events.md` and `company-metrics-dashboard.md` do not document this join; whether `FINAL` changes a `first_repossessed_at` read is unmeasured. Wake path is out of scope. Wiki stays unchanged until after approval.

### Measure outline (not executed)

Ultra-Plan, Cloud ClickHouse **READ-ONLY**, before any swap (preflight #25):

1. AC-1 agreement percent: status-log status-3 transition versus `first_repossessed_at`, with the 23% `repo_date` comparison recorded as the closed historical figure.
2. Jul 2026 UTC platform-wide `db_repos` (and `deduped`) old versus candidate. Prior snapshots 9137 (recoveries-leg measure, 2026-09-09) and 9149 (billing-view snapshot, 2026-09-25) are not the live baseline.
3. `X-ClickHouse-Summary` `read_rows`, `read_bytes`, `memory_usage` on both statements.
4. Candidate with and without `FINAL` on `clarity.hotlist`, because raw SQL does not inherit the model scope and the wiki has no measured delta for this column.

Preflight #18: Agent QA / Verify later uses the real fleet Docker ClickHouse. This prep names no host, port, or DSN.

### Gate

CLEAN. No open product question. Next is Ultra-Plan. Implement and provision stay off.

Draft PR https://github.com/insight-lpr/platform/pull/470. Assignee DaltonMcCleery was requested. The GitHub token returned 403 on both the issue assignee update and `replaceActorsForAssignable`, so the seat is not set.

## Ultra-Plan (2026-09-28 ~8:55 CT)

Phase **planned**. Gate **CLEAN**. Provisioned **false**. No product PHP. No Cloud write. No fleet DSN. No Galadriel ledger request.

First Glance **42078** re-GET confirmed live. Branch path **main→main**.

Dual-plan (#23): headless Claude draft OK (`claude-plan-draft.md`). Compare in `claude-plan-compare.md`. Authoritative `plan.md` / `plan.json`.

### Converged decisions

- Swap: status-log join → `clarity.hotlist.first_repossessed_at`; keep `public_hotlist FINAL` match.
- `{floor}`: **KEEP** (build-side filter transfers; m2c may overturn). −7 stays.
- `#25`: Cloud READ-ONLY measure before swap (AC-1, Jul 2026 db_repos, summary, FINAL, floor, min-max residue, MV backfill). 9137/9149 not baseline.
- `#18`: real fleet Docker later; no DSN.
- `#24`: no new abstractions.

Next: Provision.

## Provision (2026-09-28T14:10:34Z)

Provisioned citing Galadriel Path A :18085/:15437/:18128.

## Implement measure (2026-09-28T14:17:42Z)

Preflight #25 stopped before any product PHP. Code tip `769ad60b6365cea0254c28ab7fbb78d9d6699d4e`.

Cloud ClickHouse stayed READ-ONLY. No statement against `zod` or `clarity` ran. A `SELECT 1` probe on Production was rejected: `Forbidden. Service does not allow MCP calls`. Worker secrets do not include a read-scoped ClickHouse user. Local `.env` host `127.0.0.1` was not treated as Cloud.

Packets `ac1-agreement`, `ac3-jul-2026-db-repos`, `ac4-summary-header`, `final-on-clarity-hotlist`, `m2c-floor`, `m4-min-max-residue`, and `m5-mv-backfill` are unmeasured. Record: `docs/investigations/DEV-2660/cloud-parity-jul-2026.md`.

Historical q8 `repo_date` disagreement **1,588 / 6,770 (23%)** stays the closed comparison. It is not a `first_repossessed_at` result from this run.

`{floor}` remains KEEP. `FINAL` on `clarity.hotlist` is undecided. Swap not landed. Fleet not torn down. `cloud_touched=false`. `cloud_ch_writes=false`.

Next remains Implement, after a worker that can run the packets.

## Implement (2026-09-28T14:23:53Z measure, product after)

Phase **implemented**. Gate **CLEAN**. Next **Review**.

Preflight #25 was already green from a box-side fleet RO measure at 2026-09-28T14:23:53Z. This worker did not re-run Cloud ClickHouse and did not write to Cloud. `SELECT 1` was ok on that measure. `cloud_touched=true` (read). `cloud_ch_writes=false`.

STOP_SWAP=false. FINAL on `clarity.hotlist` is off (`db_repos` identical; FINAL costs more bytes and memory). `{floor}` stays KEEP (hash build side; floor-off blows memory ~12.2GB vs ~234MB).

| Packet | Result |
| --- | --- |
| AC-1 | 100% (9149/9149). `null_fra_triples=0`. `disagree_date_triples=0`. |
| AC-3 | Deduped 11975/11975. `db_repos` 9149/9149. Δ0. |
| AC-4 | `read_bytes` 9777707064 → 6359482618 (−35%, 34.96). `read_rows` −51% (51.15). |
| m4 | `residue_triples=0` (forecast 0.187% was not the live residue). |
| m5 | `null_first_repossessed_at=0` via FINAL and max(FRA). Naive-join false nulls discarded. |

Historical `repo_date` 23% (1588/6770) stays the closed comparison. It is not the `first_repossessed_at` result.

Product tip `255107c5b583e7f1b44c64bbc625281a0b8764aa`. `LprRepossessionsQuery` drops `zod.public_hotlist_status_log` and reads `clarity.hotlist.first_repossessed_at` on `ch.id = h.id` with `h.id != 0`, `_peerdb_is_deleted = 0`, no FINAL, `{floor}` kept. `public_hotlist FINAL` (org, vin) stays. Class stays off `ClickhouseQuery`. Shared cache keys unchanged.

Tests: `php artisan test --compact --filter=BillingClaimDedupTest|LprRepossessionsQueryTest|CompanyMetricsCacheScopeTest` — 26 passed, 129 assertions (BillingClaimDedupTest 3, LprRepossessionsQueryTest 22, CompanyMetricsCacheScopeTest 1). `CardQuerySqlFidelityTest` not edited.

Provision cite unchanged: site :18085, Postgres :15437, Docker ClickHouse :18128. Fleet not torn down. Assignee DaltonMcCleery. Jira status left unchanged.

Next is Review.

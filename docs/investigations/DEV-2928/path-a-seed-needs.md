# DEV-2928 — Path A seed needs (for the Provision twin request)

Source: checked spec `DEV-2928.md` §11 + code on `cursor/dev-2928-rdn-backfill-branches` (base `42a4310f`). Data needs only — ports / DSNs / ledger are the twin stand's call, not invented here.

## Schema rule
- Docker CH **must load `database/schema/clickhouse-schema.sql` from the DEV-2928 branch** (carries #374's `clarity.hotlist_status_transitions` + MV, `clarity.hotlist.branch_id`), so views match CI. No hand DDL.
- Expose a database literally named **`clarity`** (or rewrite consistently): `LprAssist` hard-qualifies `clarity.lpr_assists`, `clarity.recoveries` and (new) `clarity.hotlist` (`app/Clickhouse/Models/LprAssist.php` L64–65; `tests/Concerns/RequiresClickhouse.php` L14–17 warns twins may not name it `clarity`).
- `CLICKHOUSE_DRIVER=clickhouse` (pgsql mirror is not a done-signal, #18/#31). Cloud CH read-only for sizing only.

## ClickHouse tables / views read
| Object | Read by | Cite | Columns that matter |
| --- | --- | --- | --- |
| `clarity.hotlist` | new branch subquery (both cards) + transition-card comparator | schema L24; `HotlistStatusTransition.php` scopeForBranch ~L58–84 | `id, org_id, client_org_id, status, branch_id Nullable(Int64), created_at, updated_at, _version, _peerdb_is_deleted` |
| `clarity.recoveries` | LPR Assists (`FINAL`, joined `r.id = la.recovery_id`; date + org + client filters) | schema L34; `LprAssist.php` L30–65 | `id (= hotlist id), org_id, client_org_id, branch_id (copied at write), repo_date, _version, _peerdb_is_deleted` |
| `clarity.recovery_scans` → view `clarity.lpr_assists` | LPR Assists | schema L38, L89 | `recovery_id, hit_id, scan_id, kit_id, _peerdb_is_deleted` (view = `DISTINCT recovery_id … WHERE _peerdb_is_deleted = 0`) |
| `clarity.missed_opportunities` | Missed Opportunities (widget, lifetime, drill-down, ranked batch, sparklines) | schema L33; `app/Clickhouse/Models/MissedOpportunity.php` L20–52 | `id, hotlist_id, hotlist_org_id, client_org_id, branch_id UInt32 DEFAULT 0 (copied), scanned_at, integration_id, _version, _peerdb_is_deleted` |
| `clarity.hotlist_status_transitions` | comparator (branch-filtered status cards via `HotlistStatusSource`) | schema L29; `HotlistStatusSource.php` | `org_id, hotlist_id, created_at, old_status, new_status` |
| `clarity.hotlist_status_aggregate` | All Branches status cards (page render / unfiltered parity) | schema L28 | standard aggregate rows |

No CH writes from product code; seed INSERTs go only to the twin's Docker CH.

## PostgreSQL tables (zod_testing / keyed PG)
| Table | Connection | Use |
| --- | --- | --- |
| `public.orgs` | zod | RDN org needs `rdn_api_key` (+ `rdn_id`) — command refuses without key (`Org::hasRdnApiKey`) |
| `public.org_meta` | zod | `properties.rdn_rate_limit` (limiter; RDN client may update it — spec Q2) |
| `public.hotlist` | zod | selection: `org_id, integration_id (RDN), remote_order_id, status, created_at` |
| `public.hotlist_json` | zod | `json.branch_id`, `json.branch_lookup_attempts` (resolved / sealed predicate) — the only table the existing job writes |
| `public.hotlist_meta` | zod | `repo_date` (activity window) |
| `public.hotlist_status_log` | zod | `hotlist_id, created_at, old_status, new_status` (activity window; trigger stamps CURRENT_TIMESTAMP, so seed with explicit `created_at`) |
| `platform.branches` | platform (`app/Models/Branch.php` L51) | `org_id, branch_id (RDN id), branch_name` — also feeds the Client Analytics branch selector (`ClientAnalytics.php` L137) |

## Suggested row shapes
- **Orgs:** one RDN org with API key (stand-in for Action Towing) + one peer org sharing a branch id (cross-org leak check) + one non-RDN org.
- **Branches:** "Tampa Office" (A), "Atlanta" (B), plus near-duplicates for exact-vs-normalized ("Tampa Office" and "tampa office", and a punctuation variant e.g. "Tampa-Office."). A branch with NULL `branch_id` is optional (exact-wins edge).
- **Hotlists (PG + CH mirror, same ids):** ages **<30d / 30–180d / >180d**, including one >1y old with a repo date inside the window; statuses **Open, Hold, Repossessed, Closed / ClosedPositive**, one Invalid(99); RDN + non-RDN integration; one null `remote_order_id`.
- **Enrichment states (PG `hotlist_json`):** un-enriched (no row / null `branch_id`), enriched (`branch_id` set), **sealed** (`branch_lookup_attempts` = ceiling), mid-attempts (1).
- **Current branch in `clarity.hotlist.branch_id`:** A, B, **NULL**, **0**, plus a `_peerdb_is_deleted = 1` row and a peer-org row with branch A.
- **Copied-vs-current drift (the AC-4 case):** `clarity.recoveries` (+ `recovery_scans`) and `clarity.missed_opportunities` rows whose copied `branch_id` is **0 or a different branch** than the hotlist's current branch (simulates enrichment after the row was written).
- **Activity window:** `hotlist_status_log` rows inside and outside `--since/--until`; `hotlist_meta.repo_date` inside and outside.
- **Size:** QA-scale is enough (tens to low hundreds per shape); prod cardinality is measured separately on Cloud CH read-only (#25 plan §10).
- **RDN:** no live RDN from the twin — tests stub SOAP (exact name, normalized-only, unknown name, empty case, transport failure).

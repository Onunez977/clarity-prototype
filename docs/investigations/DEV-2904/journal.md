<!-- ticket-status -->
**Ticket:** [DEV-2904](https://insightlpr-team.atlassian.net/browse/DEV-2904)
**Phase:** Ultra-Prep
**Gate:** CLEAN
**Done:** intake · Ultra-Prep
**Next:** Ultra-Plan
**Open questions:** none
**Provisioned:** false
**Branch path:** main→main
**First Glance:** 42732
**PR:** https://github.com/insight-lpr/platform/pull/501 (**DRAFT**)
**Assignees:** Jira Dalton McCleery (unchanged). GitHub assignee DaltonMcCleery was requested; the integration refused `replaceActorsForAssignable`, so the PR assignee is unset.
<!-- /ticket-status -->

# DEV-2904 journal

## Intake (2026-10-02T17:39:45Z)

Live Jira re-GET. Task, To Do, Medium, Sprint 42 (id 909, active), assignee Dalton McCleery. Assignee not changed. Reporter Oscar Nunez. Issue id 31444.

One comment: **42732**, First Glance, 2026-10-02 12:39 ET, author Dalton McCleery. Verdict Ready. Hardness Med. Branch path **main→main**. CSV preferred over Excel. Surface is a VIN-level export of the LPR Repossessions `db_repos` set for the current date and org filters.

Live acceptance criteria, from the description:

- An export button (CSV or Excel) on the LPR Repossession bar chart. This prep locks **CSV**. That lock follows the house exporters and First Glance 42732. It is not a question.
- One row per VIN that makes up the chart data.
- Columns when data is available: VIN, Plate, RDN Case Number (omit or blank if not applicable), Claimed By, Repo Agent Name.
- The export respects the current chart filters (date range, client, and so on).
- Descriptive filename, for example `lpr-repossession-export-YYYY-MM-DD.csv`.

Comment 42732 said Ultra-Prep waited on DEV-2603 reaching QA Ready and on DEV-2903, and it said no branch until the Ultra-Prep kick. Live re-GET at this intake: DEV-2603 is QA Ready, DEV-2903 is Done. This run is that kick. The branch and the draft PR are in scope. Provision and product PHP are not.

Soft collisions, status at this re-GET: DEV-2659 Done, DEV-2660 Done, DEV-2737 QA Complete, DEV-2603 QA Ready, DEV-2903 Done. Unique LPR Case Claims is a sibling card, not a ticket in this search.

Base `origin/main` `b0c06779c6bcc19c78c804c628a183a012a1b701`. Branch `cursor/dev-2904-lpr-repos-vin-export-24e1`. Draft PR https://github.com/insight-lpr/platform/pull/501. Stop line: Ultra-Prep. No Ultra-Plan, no provision, no product PHP, no ClickHouse query.

GitHub assignee `DaltonMcCleery` was requested with `gh pr edit --add-assignee`. The integration returned `Resource not accessible by integration (replaceActorsForAssignable)`. The PR assignee is unset. Jira assignee was not changed.

## Ultra-Prep

Read `okf/index.md`, `okf/concepts/data-architecture.md`, `okf/concepts/company-metrics-dashboard.md`, `okf/concepts/billing-events.md`, `okf/concepts/hotlist.md`, `okf/models/hotlist.md`, `okf/models/clickhouse/hotlist.md`, `okf/models/orgs.md`, `okf/concepts/rdn.md`, and PG-030 in `okf/concepts/platform-gotchas.md`. ClickHouse was not queried. Wiki edits are queued, not made.

Company Metrics key `lpr-repossessions` is `App\Filament\Widgets\Dashboard\LprRepossessions`. Heading **LPR Repossessions**. It extends `DashboardWidget`, which extends Filament `ChartWidget`, type `bar`. The compact headline and the bars read `db_repos`. `deduped` is computed in the same statement and is not this card's number. `UniqueLprCaseClaims` reads `deduped` from its own query class.

`LprRepossessionsQuery` exposes `window()` and `monthlySeries()` only. Both return counts. The aggregate is `uniqExact((forwarder_org_id, agent_org_id, vin))` for `deduped` and `uniqExactIf` of that same triple for `db_repos`, gated by `toDate(ch.first_repossessed_at, 'UTC') >= d.claim_date - 7`. There is no row listing. A later plan adds a SELECT of the triples that pass that predicate. It does not edit the aggregate SELECT the chart already runs.

The counted grain is the triple, not `uniqExact(vin)`. One VIN claimed by two agent orgs is two `db_repos`. Parity (export row count equals chart `db_repos` for the same filters) holds when the file has one row per triple in that set. Collapsing to distinct VIN would under-count. The VIN column may repeat.

The active filter, not the 13 painted bars, is the export window. `getCardTotal()` uses the last monthly bucket for `this_month`, the previous bucket for `last_month`, and `window()` for `today` and `last_7d`. Org scope is `auth()->user()->org_id` on either the forwarder or the agent side. The page filter is the date toggle (`today`, `last_7d`, `this_month`, `last_month`, default `this_month`). This card has no client picker. "Client" in the acceptance criteria is that org and date context.

The expanded bar chart is the mounted widget (`Dashboard` sets `expanded` when the card key is `lpr-repossessions`). The compact stat is built without mounting the Livewire component. The button belongs on the expanded chart. `ChartWidget` does not implement header actions. The house `ExportAction` is used on tables. Plan wires a Filament `Exporter` (CSV, `s3`, `{high}`) so the VIN listing does not run inside the chart render.

Field candidates are in `prep-context.json`. Plate, RDN case number, and Claimed By are cited. Repo Agent Name has no per-row column on the funnel tables or in the documented `case-claim` payload, so that column stays blank. `org_meta.properties.rdn_agents` is an org-level SOAP cache and is not a VIN-level name.

## Question Gate

**CLEAN.** No open question. CSV versus Excel is locked by the house pattern and by comment 42732. Blank Repo Agent Name is the acceptance criteria's own "when available" rule, not a park that blocks planning.

Preflight 18: a later Agent QA pass needs a real fleet Docker ClickHouse. Not provisioned.

Preflight 26: branch path from comment 42732 is main→main.

Preflight 27: screenshots of the export control and a sample file come after the control exists.

## Wiki queue (do not land now)

- `okf/concepts/billing-events.md` still describes `zod.public_billing_events` as Clarity's billing read. This card reads `clarity.billing_claim_events_v`.
- `okf/concepts/dto-and-enums.md` says `HotlistJsonData` carries `case_number`. The class in `app/Dto/HotlistJsonData.php` has `branch_id` only. `case_number` lives on `zod.hotlist_json_denormalized`.
- `LprRepossessions` class docblock still says every source is `ORDER BY id` and still quotes the pre-DEV-2660 measured envelope. `clarity.billing_claim_events` is `ORDER BY (event_date, id)`.

## 2026-10-02 ~3:15pm CDT — Ultra-Plan sealed

- CloudAgent `bc-b5878f84` review: **REQUEST CHANGES** (landed `cloud-plan-review.md`).
- Opus reconcile: **APPROVE-with-adopted-changes** — all 4 Changes + 3 Nits adopted (`claude-plan-reconcile.md`).
- Sealed `plan.json` / `plan.md`. Living ticket-status → Phase Ultra-Plan · Next Provision.
- No product PHP. Cloud CH not queried. DEV-2603 remains Jeff-watch only.

## 2026-10-02 ~3:20pm CDT — Provision done

- Cited Galadriel Path A LIVE ledger (`qa-prep-ledger.json`, prove_passed).
- Smoke: login 200, PG public=139, CH plan tables present incl. hotlist_json_denormalized, SELECT 1 OK, cloud_touched=false.
- Wrote provision.md / provision.json; ticket-status → Provisioned · Next Implement.
- .context/qa-prep-ledger.json on disk (not committed).
- No product PHP. Next: Implement baton.

## 2026-10-02 ~3:38pm CDT — Implement done

- CSV export on the expanded LPR Repossessions chart. `rowsSql()` / `rows()` share the funnel WITH body. Decoration joins run after GROUP BY / HAVING. Key is `concat(toString(forwarder_org_id),'|',toString(agent_org_id),'|',vin)`.
- Aggregate window and monthly-series SQL stay byte-identical (golden sha1). No cityHash64. No maxRows. `chunkSize` 10000.
- Listing joins drop `--` comments so the ClickHouse `?` binder still sees ExportCsv placeholders. Aggregate joins unchanged.
- `this_month` / `last_month` use the UTC series month. `today` / `last_7d` and the filename date use the viewer timezone.
- Tests: `php artisan test --compact --filter=LprRepossession|BillingClaimDedup` — 74 passed, 387 assertions.
- ticket-status → Implemented · Next Review.
- Implement tip `f4e98c2352ce32704339ed249ae2cd84a4f89f11`. Cloud ClickHouse not queried. No writes. Wiki not updated.

## 2026-10-05 ~8:10am CDT — Export control moved onto the stock chart

- Deleted the copied chart-widget blade. `LprRepossessions` uses `filament-widgets::chart-widget` again.
- The expanded card's `getHeading()` renders the native `ExportAction` through `lpr-repossession-export-heading.blade.php`. Compact stats stay the plain heading string (`expanded` is false).
- Aggregate SQL untouched. Tests: `php artisan test --compact --filter=LprRepossession` — 71 passed, 310 assertions.
- Implement tip `fcc6e1f760bf5244d4a3e52058c98d2782a049f0`. ticket-status stays Implemented · Next Review.


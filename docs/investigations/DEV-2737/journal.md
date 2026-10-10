<!-- ticket-status -->
**Ticket:** [DEV-2737](https://insightlpr-team.atlassian.net/browse/DEV-2737)
**Phase:** Plan
**Done:** Ultra-Prep · Question Gate · Ultra-Plan
**Next:** Provision
**Gate:** CLEAN
**Open questions:** none
**Provisioned:** false
**Branch path:** main→main
**First Glance:** 42082
**Assignees:** Jira Dalton McCleery (unchanged). GitHub assignee DaltonMcCleery requested on #472; the integration returned 403, so it is not set.
<!-- /ticket-status -->

# DEV-2737 journal

## Intake (2026-09-28T14:19:42Z)

Live Jira re-GET. Story, To Do, Medium, Sprint 42 (id 909, active), label Carryover_Sprint41, Application Clarity, assignee Dalton McCleery. Assignee not changed. Relates the prior LPR Repossessions coverage work (QA Complete).

Comments: 42073 carryover from Sprint 41 (Yolanda Prieto). 42082 First Glance. Branch path **main→main**. Verdict Ready. Hardness Med (verify-first). Hard stop: do not invent numbers; Cloud ClickHouse is read-only measure-only.

Description drift, recorded and not edited: the body still says Sprint 41, and it still Holds the status-log retirement work until Cloud Phase B. Comment 42082 marks that Hold stale. Live status of that status-log retirement work is In Progress. Its summary is retiring the hotlist status-log join on this same card. Soft collision only. This run measures the path that is on main.

Base `origin/main` `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`. The prior LPR Repossessions coverage work commit `1bc1a0cc00f971b76c15632155ed92f10846c35f` is an ancestor.

Branch `cursor/dev-2737-lpr-repos-verify-b380`. Draft PR #472. Stop line: Ultra-Prep / Question Gate. No Ultra-Plan, no provision, no product PHP. GitHub assignee `DaltonMcCleery` was requested; the integration returned 403, so the PR assignee is unset.

## Ultra-Prep

Read `okf/index.md`, `okf/concepts/data-architecture.md`, `okf/concepts/company-metrics-dashboard.md`, `okf/concepts/billing-events.md`, and PG-030 in `okf/concepts/platform-gotchas.md`. ClickHouse stays unread. Wiki edits are queued, not made.

"LPR Repos" on Company Metrics is the card key `lpr-repossessions`, class `LprRepossessions`, heading **LPR Repossessions**. The compact headline is `db_repos`, not the sibling claims card and not the "scans per LPR repo" description.

Default page toggle is `this_month` (`app/Filament/Pages/Dashboard.php`). For that toggle, `getCardTotal()` takes the last bucket of the UTC 13-month series. The series calls `LprRepossessionsQuery::monthlySeries` with `auth()->user()->org_id` and `now('UTC')`. There is no org picker on the card.

Billing read is `{clarity_db}.billing_claim_events_v`. The class does not read `clarity.orgs`. `is_internal` is frozen earlier by `clarity.billing_claim_events_mv` from `zod.public_orgs FINAL` (name needle `insightlpr`, all org types). Recoveries still join `zod.public_hotlist FINAL` (status 3) and `zod.public_hotlist_status_log` (no FINAL, new_status 3).

No organization id for Brad Webb appears in DEV-2737, comment 42082, the same-day Hits distance work, `okf/`, or application code. The "~500+" figure is a demo impression. Neither was invented, and Cloud ClickHouse was not queried.

## Question Gate

**BLOCKED.** Q1 and Q2 are in `prep-context.json`. AC1's code path is written down. AC2 and AC3 need the org id and the UI reading before any plan or measure.

Preflight 18: a later Agent QA pass needs a real fleet Docker ClickHouse. Not provisioned. `provisioned` is false.

Preflight 24: verify-first. No product PHP and no new abstraction.

Preflight 26: branch path from comment 42082 is main→main.

## Question Gate closed (2026-09-28)

Q1: org 14, Brad Webb / premieradjusters. Q2: UI ~509 on This Month, production ClickHouse, admin impersonating Brad Webb, LPR Repos `db_repos`. Gate set to CLEAN. No tighter integer than ~509 was introduced.

## Ultra-Plan

`plan.json` and `plan.md` are the plan. No product PHP. Preflight #25 does_not_fire: the work is to re-read the existing statement, not to rewrite it.

The September 2026 window SQL is in `plan.md`. A read-only SELECT against the Production ClickHouse service was refused: `Forbidden. Service does not allow MCP calls`. `db_repos` is unset. The decision tree stays pending. No follow-on AC is filed.

`claude-plan-draft.md` and `claude-plan-compare.md` are not in this commit. Next phase is Provision. `provisioned` stays false. No fleet DSN.

## Wiki queue (do not land now)

- `okf/concepts/billing-events.md` still says Clarity's only billing read is `zod.public_billing_events`. These two funnel classes read `clarity.billing_claim_events_v`.
- `okf/concepts/company-metrics-dashboard.md` still shows `getStats()` with no per-card catch. `CompanyMetricsOverview::getStats()` catches and renders that card unavailable.
- `LprRepossessions` class docblock still says the org filter buys nothing because every source is `ORDER BY id`. `clarity.billing_claim_events` is `ORDER BY (event_date, id)`.

## 2026-09-28 ~09:36 CT — Claude-first Ultra-Plan finalize

- Opus draft (`claude-opus-5` high) + CloudAgent review (holds-with-gaps) + Opus reconcile.
- Cloud measure folded: Sep db_repos **533** vs UI ~509 → mismatch_pending_v1. SQL attached.
- plan.json/plan.md rewritten from reconcile. provisioned=false. Next Provision.

## 2026-09-28 ~10:22 CT — Provisioned

Citing Galadriel Path A LIVE ledger:
- Site http://127.0.0.1:18087 (GET /app/login → 200)
- PG 127.0.0.1:15439 zod_dev_2737 / zod_testing_dev_2737
- Docker CH 127.0.0.1:18130 clarity_dev_2737 (billing_claim_* + recovery_scans; strategy=docker)
- mac_herd_refused=true · cloud_touched=false · keyed DSNs only
- Aragorn prove: site 200 · CH billing tables present · PG TCP open · ledger anti-poison OK

phase=provisioned · Next Implement · provisioned=true · product_php=false

## Implement (2026-09-28T15:28:16Z)

Verify-only implement record. `product_php` stays false. No `app/` or `tests/` edits. No product PHP. Cloud ClickHouse was not written and was not re-queried. PHPUnit was not run.

AC-1 path re-read against the cites in `plan.json` `code_path.refs`. This Month is the last `db_repos` bucket of `LprRepossessionsQuery::monthlySeries`. Notes: `implement-notes.md`. Hop table: `reconcile.md`. Grep proof: `artifacts/monthly-series-path.rg.txt`.

M1 stays the executed Cloud measure: bucket `2026-09-01` `db_repos` 533 (`deduped` 630) versus UI impression ~509. Verdict `mismatch_pending_v1`.

V1 is `V1_PENDING`. Headline, date toggle, and Sep/Aug description integers are unfilled. Subtask t3 stays unexecuted. Follow-on AC text is in `follow-on-ac.md`, status STANDING until V1=533. No separate Jira issue filed.

V2: this worker's Postgres (`127.0.0.1:5432`, database `zod`) has no `users` table. Fleet PG `:15439` is not on this worker. Org 14 remains the Question Gate lock (Brad Webb / premieradjusters) in `plan.json` answers.

V3: impersonation cache TTL is 10 seconds (`ResolvesDashboardCacheTtl.php:30-32`).

V4: `cloud-measure-org14.sql` is hand-mirrored, predicate-equivalent to `buildSql(bucketed: true)` for org 14 through September 2026. It is not `buildSql()` output.

V5: the executed Cloud measure above.

Fleet left up: site :18087, PG :15439, Docker ClickHouse :18130. Not torn down.

GitHub assignee on #472 is already DaltonMcCleery. Jira was not transitioned.

Phase implemented. Next Agent QA. Review skipped (`NO_PRODUCT_PHP`).

`state.json` `tip_sha` records implement commit `ebb38c28b737c2758ab670178bcfd18ef345424b`.

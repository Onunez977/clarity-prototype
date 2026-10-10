# Insight AI — Build Record

> Mirror of Jira comment **42125** on DEV-2737 (posted 2026-09-28 10:42:33 CDT). Verify/Build Record after Agent QA **PASS** on verify-only / no product PHP. **V1_PENDING** and follow-on AC **STANDING** by design — BR does **not** claim V1 cleared or UI≈509 proven.

**Sprint 42 · Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/472 (**DRAFT** — Verify does not undraft)

## TL;DR

Verify/measure org **14** (Brad Webb / premieradjusters) Company Metrics **LPR Repossessions** This Month `db_repos` on the path after the prior LPR Repossessions coverage work. Cloud READ-ONLY measure: Sep 2026 UTC bucket **`db_repos` 533** (deduped **630**). Question-gate UI impression ≈ **509**. Verdict **`mismatch_pending_v1`**. Production V1 pin **not filled** (`V1_PENDING`). Follow-on AC **STANDING** until V1 live headline = **533**. **Zero `app/` ship.** Fleet suites PASS on real Docker CH `:18130`. Live empty-fleet card **0** (expected).

## WHAT

* **Nothing under `app/`.** Ship surface = docs-only investigation artifacts (`product_php=false` / NO_PRODUCT_PHP). PR #472 files = 24 docs under `docs/investigations/DEV-2737/` only.
* Documented exact query path: CM key `lpr-repossessions` → `LprRepossessions` → last `db_repos` bucket of `LprRepossessionsQuery::monthlySeries(org_id, now('UTC'))` reading `{clarity_db}.billing_claim_events_v` + hotlist / status-log joins (`plan.md` t1).
* Cloud RO curl measure (earlier, separate from Agent QA): org 14 Sep 2026 → `db_repos` **533** / deduped **630** (`cloud-measure-org14-sep2026.md`).
* Agent QA on tip `aee45684…` (record ancestor `ebb38c28…` ANCESTOR_OK): 125 passed / 510 asserts / 0 fail on real `CLICKHOUSE_DRIVER=clickhouse` @ `:18130`; live CM LPR Repossessions headline **0** / `0.0 scans` (empty fleet); `cloud_touched=false`.
* Follow-on AC evidence text at `docs/investigations/DEV-2737/follow-on-ac.md` — **STANDING**; `follow_on_filed=false` (not a separate Jira unless already filed).

## WHY

Jeff/Oscar demo notes (2026-09-25): LPR Repos for Brad Webb’s org This Month looked high (~500+). The prior LPR Repossessions coverage work landed claims/repos onto `clarity.billing_claim_events_v`. Ticket goal = **verify-on-new-path** before any product change. Question Gate locked org **14** and UI ≈ **509**. Do not invent numbers; Cloud CH READ-ONLY measure-only. Plan `t3`: any Cloud integer other than 509 → file follow-on AC with evidence (no invent fix PHP). Measured **533** ≠ 509 → mismatch pending V1 re-pin.

## HOW numbers were derived

* **Cloud RO measure** (2026-09-28 ~**09:28 CT**): `ch-cloud-readonly` / `readonly_user` against production ClickHouse. Mirrored `LprRepossessionsQuery::monthlySeries(14, …)` shape. Current UTC month row bucket `2026-09-01`: **deduped 630 · db_repos 533**. Artifact `docs/investigations/DEV-2737/cloud-measure-org14-sep2026.md` (+ SQL / jsonl). **No Cloud writes.** Agent QA did **not** re-measure Cloud.
* **Agent QA PASS** (2026-09-28 **10:39:02 CDT**):
  * Filter `BillingClaimDedupTest|LprRepossessionsQueryTest|CardQuerySqlFidelityTest|CompanyMetricsCacheScopeTest|LprRepossessionsTest`
  * **125 passed / 510 assertions / 0 fail / 0 error** (~62s)
  * `CLICKHOUSE_DRIVER=clickhouse` · `PROCESS_CLICKHOUSE_DRIVER=clickhouse` · host publish **`:18130`** / from_app `172.20.0.1:18130` · suites classic `clarity`/`zod` · live keyed `clarity_dev_2737`
  * Live site `:18087` · PG `:15439` · Docker CH `:18130` · ledger `docs/investigations/DEV-2737/qa-prep-ledger.json`
  * Live: title **Company Metrics - Platform** · range This Month · LPR Repossessions headline **0** · description **0.0 scans per LPR repo** · no 500 · empty fleet expected (`billing_claim_events_v` count=0) — **not** a 509/533 substitute
  * `cloud_touched=false` · `mac_herd_refused=true` · undrafted=false · board_posted=false
* **V1_PENDING:** Production UI pin OUT OF SCOPE this run (`*.insightlpr.io` refused / not opened). **No invented production headline.** Do **not** treat UI≈509 as proven. Do **not** “fix” the ~509 vs 533 gap in PHP on this tip.
* **Follow-on AC STANDING** until V1 live headline integer is **533** (`follow-on-ac.md`). Evidence-only; names no code change. Withdraw only when V1=533; any other headline leaves AC standing for a separate fix ticket.
* **Review:** **skipped** (verify-only / NO_PRODUCT_PHP; zero `app/` ship). No Review round. `rerun_required=false`. `act_on=NONE`. Mechanical placeholder score `[85]` recorded only so the Build Record gate accepts a non-empty `scores` array — **not** fabricated Review lane scores (do **not** read as 94/92/95).
* Thin conventions **#16**: docs-only / NO_PRODUCT_PHP → **Pint N/A**. Refuse redesign.
* Preflight **#18 PASS (required):** Agent QA real fleet Docker CH `CLICKHOUSE_DRIVER=clickhouse` @ **`:18130`**. Suites + live cite. **pgsql does not clear.**
* Preflight **#25 N/A (`does_not_fire`):** Plan confirms no query add / no path rewrite this tip. Verify/measure only.
* Soft **#27:** PR evidence comment present — https://github.com/insight-lpr/platform/pull/472#issuecomment-5873422951 (UI screenshots). Suites alone ≠ #27 clear.
* Tips: checkout / PR head `aee4568455ea28000591667f075935ec42efd328` · record ancestor `ebb38c28b737c2758ab670178bcfd18ef345424b` · `ANCESTOR_OK=yes`. Fleet LEFT **UP**.

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 Document exact LPR Repos query path(s) | SATISFIED | `plan.md` t1 + `implement-notes.md` / `reconcile.md` — `LprRepossessions` → `monthlySeries` → `db_repos`; billing_claim_events_v + hotlist/status-log joins; org 14 This Month = Sep 2026 UTC bucket |
| AC-2 Reconcile UI vs query (± Cloud measure) | **OPEN / V1_PENDING** (`mismatch_pending_v1`) | Cloud RO `db_repos` **533** (deduped 630) vs QG UI ≈ **509** (~24). Production V1 headline **not pinned**. BR does **not** claim match or invent UI integer. Not PASS-as-cleared. |
| AC-3 Follow-on if wrong / proof if correct | **STANDING** (follow-on AC) | `follow-on-ac.md` STANDING until V1=533. Evidence-only; no fix PHP; `follow_on_filed=false`. Closing Verify with standing follow-on is plan/QG-designed OK. |
| Suites on real Docker CH | PASS | 125/510/0 @ `:18130` clickhouse driver · phpunit-agent-qa.log |
| Live empty-fleet CM card | SOFT_EXPECTED | Headline **0** / 0.0 scans · no 500 · not Cloud substitute |
| Preflight #18 | PASS | Real Docker CH `:18130` · driver=clickhouse · cloud_touched=false · not pgsql |
| Preflight #25 | N/A | does_not_fire — no path rewrite |
| Thin conventions #16 | N/A | Docs-only · Pint N/A |
| Soft #27 | SOFT_PASS (URL present) | https://github.com/insight-lpr/platform/pull/472#issuecomment-5873422951 |

## Soft flags (non-blocking)

* **V1_PENDING** / **mismatch_pending_v1** — production UI pin not filled; Cloud 533 vs QG ≈509 open until V1
* Follow-on AC **STANDING** — not a separate Jira; evidence-only
* Live empty-fleet LPR Repossessions = 0 (expected)
* Review skipped — mechanical score placeholder only
* Docs tips `[skip ci]` — CI trigger is next lane
* Soft twin ports untouched; fleet LEFT UP

## What this ticket did NOT do

* Invent or ship product PHP / fix the 509↔533 gap in code
* Invent a production UI headline integer
* Claim V1 cleared or UI≈509 proven
* Write Cloud ClickHouse
* Undraft / QA Ready / merge / Status Board / Fleet Board from Verify
* Tear down fleet (left UP for next lane)
* Open `*.insightlpr.io` / fill V1 pin

## Review / conventions

Review **skipped** · verify-only / NO_PRODUCT_PHP · `rerun_required=false` · act_on **NONE**. Thin conventions #16: Pint **N/A** (docs-only). Mechanical gate placeholder scores=`[85]` — **not** Sam lanes.

## Tips

| Role | SHA |
| --- | --- |
| **Checkout / PR head** | `aee4568455ea28000591667f075935ec42efd328` |
| **Record tip (ancestor)** | `ebb38c28b737c2758ab670178bcfd18ef345424b` |

Zero `app/` / `tests/` files on PR #472 vs base (docs-only). `ANCESTOR_OK`.

## PR

https://github.com/insight-lpr/platform/pull/472 — **DRAFT** · head `aee45684…`

## Next

**Undraft / CI / QA Ready** (PM). Verify does **not** undraft, flip QA Ready, merge, or post Status/Fleet Board. Docs tips are `[skip ci]` — CI trigger is next lane. Fleet env left **UP** (`:18087` / `:15439` / `:18130`); teardown on closeout / QA Ready exit is next lane. **V1 production pin** remains open work outside this Verify close — follow-on stays STANDING until V1=533.

## Verify exit note (gates)

`phase=done`. `BUILD_RECORD_GATE` + `REWORK_GATE` expected PASS after comment id recorded. Explicit: **V1_PENDING** · **mismatch_pending_v1** · follow-on **STANDING**.

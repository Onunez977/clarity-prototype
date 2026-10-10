# DEV-2304 — Plan

**Route:** bug · **Base:** main · **Resolution:** A — Clarity Pro Plus **is** entitled to RDN Statistics Push

## The defect, in one paragraph

The RDN Statistics Push toggle is *rendered* for any tier above Basic
(`resources/views/filament/pages/subscription.blade.php:431`) but the backing Livewire action
*rejects* anything that isn't Pro Lite or Clarity Pro with a bare `return;`
(`app/Filament/Pages/Subscription.php:194`). A Clarity Pro Plus admin therefore gets a
live-looking switch whose every click is silently swallowed — no write, no audit entry, no
notification, no dispatched event. `app/Jobs/Rdn/DispatchRdnMetricsHourly.php:105-109` carries a
hand-duplicated copy of the same allow-list on the read side, so the write gate and the read gate
must move together. Independently: the action emits no feedback on **any** path for **any** tier,
so AC5 was never met even where the toggle works.

A grep backstop confirmed these are the **only two** hard-coded tier allow-lists in the repo that
omit `CLARITY_PRO_PLUS`. The exhaustiveness sweep is closed, not sampled.

## Subtask DAG

One node — every change lands in a single method plus one job filter plus their two test files.
Fanning out would put two workers inside the same method.

### t1 — Entitle Pro Plus on both gates, and give the toggle real feedback
`complexity: complex` · `blast_radius: billing-adjacent (paid-tier entitlement)` ·
approved by Dalton in the Phase 2 interview · `test_filter: SubscriptionTest|DispatchRdnMetricsHourlyTest`

| File | Change |
| --- | --- |
| `app/Filament/Pages/Subscription.php` | Add `ProTierEnum::CLARITY_PRO_PLUS` to the allow-list at :194. Send a success `Notification` after the write; replace each bare `return;` (non-admin :188, non-entitled tier :194) with a `->danger()` notification so no click is silent. Preserve the `activity('rdn_metrics_toggle')` entry and the property assignment as-is. |
| `app/Jobs/Rdn/DispatchRdnMetricsHourly.php` | Add `ProTierEnum::CLARITY_PRO_PLUS` to the PHP-side tier filter in `eligibleOrgs()` (:105-109). Nothing else — the SQL predicates, the `rdn_id` trim and the pending-downgrade rationale stay. |
| `tests/Filament/Pages/SubscriptionTest.php` | Pro Plus toggle on / off / persistence / `mount()` read-back, plus notification assertions on the success and non-admin paths. Extends the existing `#[Group('rdn-metrics')]` block. |
| `tests/Feature/RdnMetrics/DispatchRdnMetricsHourlyTest.php` | Pro Plus eligibility via the existing `makeRdnEligibleOrg(...)` helper — the write/read gate parity test. |

**TDD order:** write the failing Pro Plus toggle test and the failing dispatcher-eligibility test
first, confirm red, then make them green.

**Test-fixture trap:** `createUser(pro: true)` / `makeOrgPro()` write no explicit tier, so
`Org::proTier()` backward-compat-defaults them to `CLARITY_PRO`. Every existing test lives in the
entitled tier universe by construction — which is exactly why this shipped unnoticed. The Pro Plus
tier must be written explicitly via `org_meta.properties.platform_repo_pro_tier`.

**Blade:** no change. Under Resolution A the entitled set is exactly the non-Basic tiers, so the
existing `$currentTier !== BASIC` render condition and the widened allow-list now agree.

## Deliberate deferrals

- **De-duplicating the entitled-tier set** into one `ProTierEnum` helper shared by the write gate,
  the read gate and the render condition is the durable structural fix for this bug class — but it
  isn't needed for any AC and would widen a 4-file bug fix into an enum + call-site refactor. Under
  Resolution A no user-visible divergence remains, and the new parity test catches future drift.
  Raised as a linked follow-up task at handoff. Recorded here so review reads this as a considered
  decision, not an oversight.
- **`admin_impersonator` bypass** on the tier gate — declined by Dalton in the Phase 2 interview.

## Gates

- **Sizing:** 4 files, one concern. Under the 6-file threshold; no split.
- **Perf:** not required (`perf_validation.required = false`) — no >1B-row ClickHouse surface is
  introduced or re-shaped.
- **Review:** escalate to **3 lanes** — paid-tier entitlement territory plus a blast-radius tag.

## Capacity footnote

Widening eligibility means more orgs enter the hourly dispatcher, increasing read volume against
`clarity.scan_report_hourly` and `clarity.hits_hot` on the existing hourly cadence. Bounded by the
number of Pro Plus orgs that opt in — a capacity note for the Build Record, not a design risk.

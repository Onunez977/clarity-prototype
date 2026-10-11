# Journal — DEV-2739

<!-- ticket-status -->
**Ticket:** [DEV-2739](https://insightlpr-team.atlassian.net/browse/DEV-2739)
**Phase:** Plan
**Done:** intake · Ultra-Prep · Question Gate CLEAN · Ultra-Plan · dual-plan compare
**Next:** Provision (HELD — open Jeff FAIL / Off GO on DEV-2736 / platform#468; no ledger request until cleared)
**Gate:** CLEAN
**Canon:** `okf/concepts/hit-address-distance.md` (main #467 / `19e56a5a`) — paths and N/A rules PROVEN. Brad Webb root cause still unclassified after the t1 packet.
**t1:** Cloud CH read-only measure DONE 2026-09-25 ~14:50 CT (`measure.md`). Provision still HELD.
**Demo path:** admin impersonation of org 14 → ClickHouse list → Path B. Locked. Not a classified cause.
**Locked:** Q1 org 14, filters, both columns N/A · Q2 measure read-only before any data-vs-fix call · Q3 defer unless the same hits disagree
**Dual-plan:** compare landed; plan.md/plan.json authoritative with M0 + mirror-freshness amendments; Claude draft rejected for Path A swap pre-Q3
**Root cause:** unclassified (data vs query vs UI). Not invented.
**Provision:** HELD. Not provisioned. No fleet ledger requested. No product code. Cloud ClickHouse not written.
**Jira assignee:** Dalton McCleery (unchanged, To Do).
**PR assignee:** DaltonMcCleery
**Sprint:** Sprint 41
**Branch path:** main→main
**Soft relate:** [DEV-1611](https://insightlpr-team.atlassian.net/browse/DEV-1611) Done — fresh bug, not a reopen
**Live First Glance:** comment 41873 (supersedes 41853) · Gate close 41877
**Artifacts:** docs/investigations/DEV-2739/
<!-- /ticket-status -->

## Intake (2026-09-25T18:46:38Z)

Re-GET DEV-2739 on cloud `150e0ba0-1d8e-4ac4-82da-ee78a512c3cc`. Live fields match the brief:

- Bug · Medium · To Do · Sprint 41 · Application Clarity · assignee Dalton McCleery. Not reassigned. Workflow status not transitioned.
- Relates DEV-1611 (Done, Story, parent epic DEV-1310 Clarity Pro Launch). Soft relate only. Not reopened.
- Description AC 1–4 unchanged. No attachments.
- Comments: 41853 (First Glance, hold) and 41873 (hold lifted, supersedes the queue line). 41873 is the live glance. The 41853 queue lock is stale. Branch path main→main. Hard stop: do not invent root cause before reproduce; do not treat missing given-address data as a product fix without Dalton.

`origin/main` fetched. Base `e09b222e15b912bd59542e800e5e0171c08d82eb`. Branch `cursor/dev-2739-hits-distance-na-prep-439d`.

Read `okf/index.md` and `okf/concepts/data-architecture.md` before the data-path trace. Canon for these columns is `okf/concepts/hit-address-distance.md` (merged in #467 / `19e56a5a`, commit `03e24eec`). Cross-links: `okf/concepts/hit.md`, `okf/concepts/hotlist.md`, `okf/concepts/dto-and-enums.md`, `okf/concepts/feature-flags.md`. The path and N/A rules on that page are the proven contract. The page's UNPROVEN list stays unproven, including the Brad Webb root cause. Wiki was not edited.

Stop for this run: Ultra-Prep. No Ultra-Plan, no provision, no product code, no Cloud ClickHouse write.

## Ultra-Prep (read-only code)

Hits list columns **Distance from Given** and **Nearest Given Address** live on `HitsResource`. The list query is chosen in `ListHits::getTableQuery()`.

Two computation paths are in the code and match the wiki contract. The 2026-09-25 demo was a staff showing of Brad Webb's company. That mode uses admin impersonation. Impersonation forces the ClickHouse hit list, so these two columns take Path B. That is the likely list mode. It is not a classified cause of all-N/A.

**Path A — PostgreSQL list query.** `ListHits::buildPostgresQuery()` adds two correlated subqueries when `FeatureEnum::HIT_ADDRESS_DISTANCE` is active or `admin_impersonator` is in the session. Source is `hotlist_address` joined to `address` on the `zod` connection (`public` schema, because `Hit` uses that connection). Filter is `a.geo IS NOT NULL AND scans.geo IS NOT NULL`. Order is nearest `ST_DistanceSphere`, `LIMIT 1`. No `address.type_id` predicate. No `(0,0)` sentinel exclusion. Aliases: `distance_from_given_miles`, `nearest_given_address`.

**Path B — ClickHouse list rows, then a per-cell POST.** `ListHits::buildClickhouseQuery()` selects hit columns including `lat` and `lng`. It does not select the distance aliases. `HitsResource` formatters, when the column state is null or `'-'` and `hotlist_id`, `lat`, and `lng` are all truthy, return Alpine blades. Those blades call `fetchNearestGivenAddress` → `POST /nearest-given-address` (`NearestGivenAddressController`). The controller reads `clarity.recovery_address FINAL` where `id` equals the hotlist id and `geo.1 != 0 AND geo.2 != 0`, then picks the nearest row in PHP (Haversine, 3958.8 mi). Empty JSON becomes blade text `N/A`.

`shouldUseClickhouse()` is `Feature::active('clickhouse:hits')` or admin impersonation. Pennant defines `FeatureEnum::CLICKHOUSE_HITS->slug()`, and `Str::slug('Clickhouse Hits')` is `clickhouse-hits`. `tests/Filament/Resources/ClickhouseRoutingTest.php` records that the colon literal matches no defined feature, that Scans was moved off the same literal, and that ListHits still has it. A non-impersonating session therefore does not take the ClickHouse hit list through that string. Admin impersonation always does, and that is the likely demo mode above. Whether `'clickhouse:hits'` equals the Pennant slug `clickhouse-hits` stays UNPROVEN on the wiki page; the impersonator bypass does not depend on that string.

`clarity.recovery_address` in `database/schema/clickhouse-schema.sql` is a `ReplacingMergeTree` ordered by `id`, `geo Point DEFAULT (0, 0)`. The PostgreSQL object it mirrors is `public.recovery_address` in `vendor/insight-lpr/laravel-zod-migrations/database/schema/testing-schema.sql`: a materialized view of `hotlist_address` ⋈ `address` where `a.type_id = 2`, `row_number() OVER (PARTITION BY hotlist_id ORDER BY address_id) = 1`. `okf/concepts/hotlist.md` calls `type_id = 2` the repo / address-of-recovery. That label is wiki text. Whether it is the product meaning of "given address" for these columns is unproven. DEV-1611's story text says distance to the nearest given / hotlist address, and a dash when the order has none.

N/A is a literal string in three places: `HitsResource` formatters when the fallback cannot start (missing hotlist id or falsy lat/lng, and PHP treats `0` as falsy); both blades when the POST returns no address or no `distance_miles`; the controller's empty JSON, which the blades render as N/A (feature off without impersonator, request lat or lng equal to 0, or no non-zero `recovery_address` row). The Pro teaser blade is a different render and only runs on Nearest Given when the feature is off and the viewer is not an impersonator. The ticket reports the string N/A, so the columns were rendering the fallback, not the teaser. That does not say whether the empty result came from data, the query source, or the formatter short-circuit.

The Hits status filter defaults to `1` (`HotlistStatusEnum::Open`). DEV-2739 does not record the demo's filters. DEV-1611 QA used Repossessed / plate <plate> / last 30 days on a staging account. Those filters are not copied onto this bug.

No PHPUnit covers `NearestGivenAddressController` or these two column formatters.

Seed and factory search found no Brad Webb, Premier Adjusters, or `premieradjusters` row. No production org id is recorded here.

## DEV-1611 code history (not a reopen)

Shipped on `v3.5.0` (#117, `475bd168`, 2026-04-14), author daltonmccleery:

| Commit | Date | What landed |
| --- | --- | --- |
| `8c74c7c9` | 2026-03-23 | `HIT_ADDRESS_DISTANCE` on `FeatureEnum`, Pennant, Pro Lite / Clarity Pro / Clarity Pro Plus |
| `c50e5819` | 2026-03-24 | Postgres subqueries + Pro teaser + column toggle |
| `2e121335` | 2026-03-24 | ClickHouse list subqueries against `clarity.recovery_address` (same day, removed next) |
| `8532918a` | 2026-03-24 | Those list subqueries removed. Controller, blades, `fetchNearestGivenAddress`, route added |
| `06ee002d` | 2026-03-25 | Bound `?` for `ra.id` |
| `dc16a11c` | 2026-03-26 | Bound parameter removed; id interpolated after the int cast (current controller) |
| `d7759844` | 2026-04-13 | `->visible()` on Nearest Given Address. Message is `DEV-1611` |

DEV-1611 comment 19037 names a Brad Webb test (Repossessed, plate <plate>, last 30 days). Comment 20086 (Yolanda Prieto, 2026-04-03, staging) says that account rendered `5409 King Henry Dr, Austin, TX 78724` and distances 0.01–0.02 mi. That is historical QA, not a current production measurement and not an org id.

DEV-2163 plan text left `NearestGivenAddressController` on `recovery_address` while moving Scout check-ins off that view. That sentence is a prior plan, not a DEV-2739 acceptance criterion.

## Canon fold-in (2026-09-25T18:49:22Z)

Re-read `okf/concepts/hit-address-distance.md` on this base. Proven contract, cited from that page and matched in code:

- Feature `HIT_ADDRESS_DISTANCE`, Pennant value `platform_repo_pro_hit_address_distance`, granted on Pro Lite and above.
- DEV-1611 is Done and a soft relate, not a reopen.
- Path A is the Postgres list: nearest row over `zod.public.hotlist_address` ⋈ `address` with `geo IS NOT NULL`. No `type_id` filter. No `(0,0)` exclude. Aliases `distance_from_given_miles` and `nearest_given_address`.
- Path B is the async ClickHouse fallback: list state null or `'-'` with truthy `hotlist_id`, `lat`, and `lng` → blades → `NearestGivenAddressController` → `clarity.recovery_address FINAL`. The Postgres MV behind that table is `type_id = 2` only, one row per hotlist. Admin impersonation forces the ClickHouse hit list, so these columns always use Path B in that session.
- N/A is correct when scan lat/lng is missing or 0, when the active path has no qualifying geocoded address, or when the feature is off for a non-impersonator.
- N/A is a bug candidate only after the active path is known, scan geo is non-zero, and that path's address source has at least one qualifying row, and the UI still shows N/A.

Still UNPROVEN, and not decided here: Brad Webb all-N/A root cause (data vs query vs UI); which path is product-canonical for "given address"; whether Path A's missing `(0,0)` exclude is intentional; whether `Feature::active('clickhouse:hits')` matches Pennant slug `clickhouse-hits`.

## Gate

BLOCKED at prep close. The contract is proven. The Brad Webb root cause is not.

## Question Gate CLEAN (2026-09-25T19:02:00Z)

Locked answers, not inferred:

- Q1. Org 14, brad.webb@premieradjusters.com / premieradjusters. Viewer is DaltonMcCleery in admin mode, impersonating that account. Filters: Type All, Status Open, FlexSearch on, Location Cluster off, Disposition New Hit + Accessible, Hit date Today, Scanned date Any. Every visible row is N/A on both columns. Evidence is the Hits screenshot from the 2026-09-25 interview, about 1:54 PM CT.
- Q2. Measure on Cloud ClickHouse, read-only, before calling the rows a data gap or a product fix. The hard stop holds until that measure lands.
- Q3. Defer. Pick a given-address source only if Path A and Path B disagree on the same hits. No winner now.

Path A’s missing `(0,0)` exclude, and whether `Feature::active('clickhouse:hits')` matches `clickhouse-hits`, stay non-blocking. Impersonation forces the ClickHouse list without that string.

## Ultra-Plan

`plan.md` and `plan.json`. Measure first (t1), classify (t2), and a code change only if qualifying rows exist and the UI is still N/A (t3). This commit does not run the measure and does not change product code. Next is a dual-plan compare. Provision waits for the fleet ledger after that compare.
## 2026-09-25 ~14:45 CT — Dual-plan compare + Provision HOLD

- Claude draft landed via stdin retry (`claude-plan-draft.md`).
- Compare written (`claude-plan-compare.md`); plan.md/plan.json lightly amended (M0, mirror-freshness, optional status strat for Q3 packet). Rejected Path A controller swap before Q3.
- Galadriel / CEO: HARD STOP before Provision while Jeff FAIL / Off GO open on DEV-2736/#468. No ledger request. No mark provisioned. No Implement.

## 2026-09-29 08:15 CT — QG CLOSED (Dalton via Gandalf): Loosen Given → Path A
- **Lock:** Given = any geocoded hotlist address on the order (no `type_id` filter). Not recovery_address / type_id=2 only.
- Oscar: type_id=2 is repo but wrong for Hits page. Glorfindel: only 2+NULL proven; no other named types.
- Plan/PR #469 targets Path A (drop type_id=2 filter on Given).
- **Do not invent implementation.** Await Galadriel Board/spine kick before Implement / re-provision.
## 2026-09-25 ~14:50 CT — t1 measure

Cloud CH READ-ONLY t1 measure completed 2026-09-25 ~14:50 CT via HTTPS readonly_user (MCP forbidden on Production service). Packet: `docs/investigations/DEV-2739/measure.md`. Provision still HELD. No product PHP. Not provisioned.

Q3 multi-org Path A vs B contrast done 2026-09-25 ~14:55 CT (5 orgs, Cloud CH RO).

Path B gap dive done 2026-09-25 ~15:00 CT — root class mirrored-but-NULL-type_id vs recovery_address_mv type_id=2.

Glorfindel cite folded into measure-path-b-gap (PG recovery_address MV type_id=2; CH adds geo filter).

## 2026-09-29 08:17 CT — Galadriel Board reopen · Path A plan retarget
- Galadriel: DEV-2739 reopen on Dalton Path A lock. Re-kick plan/PR #469; Path A provision when asked.
- Authoritative plan.json/plan.md rewritten: NearestGivenAddressController → Path A Postgres (any geocoded hotlist address, no type_id). recovery_address_mv out of scope.
- Prior Provision HELD (2736 FAIL) superseded for this Path A retarget per Board kick.
- Next: ask Galadriel for LIVE fleet ledger, then Provision.

## 2026-09-29 08:33 CT — Review rn=1 PASS

- Sam Review rn=1 written under `.ai/` (gitignored): correctness 94 · conventions 93 · blast-radius 95 · threshold 85.
- `reviewed_sha` / product tip `8c093a1fee8e42bd329bfea3816a5ead09b57056` · branch tip `453e5d3fc9bd306cd6b7af4aa62f8bf11641eea7` · PR #469 base main.
- Gate: `REVIEW_GATE: pass min=93 threshold=85 lanes=3 rn=1 scores=94/93/95 conventions=93 sources=in-session-review:3 source=lane-min` · GATE_EXIT=0 · act_on **NONE** · next **Pippin** (Agent QA).
- Soft OK vs: org 14 Brad Webb manual QA on fleet :18089/:15441/:18132; okf deferred WRITE-after-approval.
- Mirrored `.ai/` → `/workspace/DEV-2739/.ai/`; gate at `/workspace/DEV-2739/gates/review-gate.sh`.
- `.ai/` not git-added/committed. Fleet LEFT UP (do not tear).

## Verify / Build Record — 2026-09-29 ~09:08 CDT
- Agent QA PASS cited (7/22 @ :18132; live Path A pins). #18 PASS · #25 does_not_fire · #27 SOFT_PASS · #26 main→main.
- Posted Build Record Jira comment **42304**. Living PR ticket-status rewritten (DRAFT stays).
- Gates: BUILD_RECORD_GATE pass id=42304 (route=bug, ac=11, review_rounds=3, rerun_required=false); REWORK_GATE pass (only investigation artifacts).
- phase=done · next Undraft/CI/QA Ready · fleet LEFT UP :18089/:15441/:18132 · DEV-2601 twin untouched · handoff `/workspace/DEV-2739/handoff/galadriel.md`.

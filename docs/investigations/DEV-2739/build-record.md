# Insight AI — Build Record

> Mirror of Jira comment **42424** on DEV-2739 (tip-fix rn=2 Verify/Build Record after Jeff FAIL J+M). Posted 2026-09-29 16:09 CDT (Jira created 17:09:25 -0400). Prior BR **42304** (Path A Given Verify) remains cited.

**Sprint 42 · Repo** `platform` · **PR** https://github.com/insight-lpr/platform/pull/469 (**READY** — Verify does not undraft / QA Ready)

## TL;DR

Prior Build Record **42304** (Path A Given = any geocoded hotlist address, no type_id) remains cited. **This** Build Record is the tip-fix **Jeff FAIL J+M** re-Verify after FREEZE FAIL on Last 7d Search (J) and nearest-given-address fetch flood (M).

- **Product tip / reviewed_sha / branch tip (same):** `3eb652351ba2bbdeb170f7becccf9485e3a4b1bd`
- Ancestors: Last 7d `ab9d10eeb9fa6ed0d3b801b9139527cddddd7dd3` · Path A `8c093a1fee8e42bd329bfea3816a5ead09b57056`
- Jeff fail tip: `01c64969dd0137df925c5928e7f36cd65f961f69`
- Agent QA tip-fix **PASS** · Review rn=2 **95 / 93 / 94** · soft #27 tip-fix present · Path A Given **unchanged** · `PRODUCT_TIP_ANCESTOR_OK` (tip == reviewed)

## WHAT changed (tip-fix J+M only)

* **J — Last 7d Search bounds** (`ab9d10ee`): Hit Date presets landed on `date_toggles` while `start`/`end` stayed on the previous window (today). Search now writes the preset window into the filter bag before Filament applies it (`ListHits::applyTableFilters` + `DateTogglesFilter` / `AppliesOttoPageFilters`). Yesterday's hits appear under Last 7d + Search.
* **M — nearest-given-address fetch `.catch`** (`3eb65235`): Distance and Nearest Given blades called `fetch(…).then().finally()` with no `.catch`. Failures now `.catch` → clear value → Distance/Nearest stay **N/A**; loading cleared; `x-show` instead of bare `x-if` scope so no console flood / unhandled rejection.
* **Path A Given unchanged:** `NearestGivenAddressController` + `HitsListQuery` **byte-identical** vs Jeff fail tip `01c64969` and vs Path A tip `8c093a1` for Given logic. No Cloud CH writes. No `recovery_address` reopen.

## WHY

Jeff FAIL J+M on tip `01c64969…`: (J) Hit Date Last 7d + Search stayed stuck on today — yesterday QA2739J never rendered; (M) forced/failed `/nearest-given-address` rejected with no catch → console flood / cells never settled on N/A. Prior BR 42304 covered Path A Given only.

## HOW numbers were derived

* **Agent QA tip-fix PASS** (2026-09-29 **16:06 CDT**):
  * Suites `NearestGivenAddressControllerTest` + `HitsResourceTest::test_last_7d` → **8 passed / 30 assertions / 0 fail**
  * Real Docker CH @ **`:18132`** · db `clarity_dev_2739` · **not pgsql** · `cloud_touched=false`
  * Live Hits `:18089` · PG `:15441/zod_dev_2739` · brad.webb@premieradjusters.com org **14** · Open · New Hit+Accessible
  * **J PASS** — Hit Date Last 7d + Search → yesterday QA2739J (Sep 28 CDT); not stuck on today
  * **M PASS** — forced 500 → Distance/Nearest **N/A**; loading cleared; tip `.catch` (no console flood)
  * Path A smoke PASS — Lakeside / King Henry; zero+null-geo → N/A
  * Evidence: `docs/investigations/DEV-2739/live-qa-evidence-tipfix/` · `qa-agent-report.md` · `qa-ac-results.json` · `phpunit-tipfix-agent-qa.log`
* **Review rn=2:** scores **[95, 93, 94]** · min 93 ≥ 85 · `reviewed_sha=3eb652351ba2bbdeb170f7becccf9485e3a4b1bd` · `rerun_required=false` · act_on **NONE** · scope J+M tip-fix only
* Thin conventions **#16:** conventions lane **93** ≥ 85 — refuse redesign
* Preflight **#18 PASS (required):** Agent QA real fleet Docker CH @ **`:18132`**. Suites **8/30** + pins cite. **pgsql does not clear.** `cloud_touched=false`
* Preflight **#25 N/A (`does_not_fire`):** same as prior BR 42304 — Path A lock ≠ old Path B parity; tip-fix is J+M only
* Soft **#27 SOFT_PASS:** tip-fix https://github.com/insight-lpr/platform/pull/469#issuecomment-5898896098 (prior Path A 5891903772 optional)
* Preflight **#26:** base **main** → **main→main** (Jeff merges after QA Ready). Not holding scout.
* Tip spot-check (Verify): both blades have `.catch` at tip; Last 7d Search applies bounds (`ab9d10ee`); Path A controller unchanged vs `8c093a1` for Given logic; Controller+HitsListQuery byte-identical vs `01c64969`.

## AC verdict

| AC | Verdict | Evidence |
| --- | --- | --- |
| AC-1 Reproduce N/A Brad Webb org + filters | PASS | Prior Path A + tip-fix org 14 Open New Hit+Accessible |
| AC-2 Classify fault (data / query / UI) | PASS | Prior Path B type_id root; tip-fix = J filter bag + M missing .catch |
| AC-3 After fix: real Distance/Nearest where source exists; N/A only when unavailable | PASS | Path A Lakeside/King Henry; M forced 500 → N/A |
| AC-4 Regression / type_id=2 still works | PASS | Suite + live type_id=2 pin King Henry |
| Product lock Path A no type_id | PASS | Controller tip byte-identical vs 8c093a1 Given logic |
| **J Last 7d Search** | PASS | Live yesterday QA2739J Sep 28; suite test_last_7d |
| **M nearest fetch .catch → N/A** | PASS | Forced 500 → N/A; tip .catch; no console flood |
| Path A smoke | PASS | Lakeside / King Henry; zero+null-geo N/A |
| Suites on real Docker CH | PASS | 8/30 @ `:18132` |
| Preflight #18 | PASS | Docker CH `:18132` · not pgsql · cloud_touched=false |
| Preflight #25 | N/A | does_not_fire — plan cite; Path A lock ≠ old Path B parity |
| Thin conventions #16 | PASS | conventions 93 ≥ 85 |
| Soft #27 tip-fix | SOFT_PASS | https://github.com/insight-lpr/platform/pull/469#issuecomment-5898896098 |
| Branch path #26 | NOTED | main→main; Jeff merge after QA Ready |

## Soft flags (non-blocking)

* okf `hit-address-distance.md` WRITE deferred until after approval
* Docs tips `[skip ci]` — CI trigger is next lane
* Soft #27 tip-fix evidence live on PR 469
* Fleet LEFT UP `:18089` / `:15441` / `:18132` — do **not** tear; DEV-2601 twin `:18088`/`:15440`/`:18131` untouched
* Fleet-local ASP/`__qa` patches not committed
* PR already **READY** — Verify does **not** undraft / flip QA Ready; Galadriel owns re-QA Ready / HITL if needed

## What this tip-fix did NOT do

* Change Path A Given / `NearestGivenAddressController` / `HitsListQuery`
* Widen `clarity.recovery_address_mv` / PeerDB type_id=2
* Cloud ClickHouse writes
* Undraft / QA Ready / merge / Status Board / Fleet Board from Verify
* Tear down this fleet or the DEV-2601 twin

## Review / conventions

Review rn=2 tip-fix · **95 / 93 / 94** · min 93 ≥ 85 · `reviewed_sha=3eb652351ba2bbdeb170f7becccf9485e3a4b1bd` · `rerun_required=false` · act_on **NONE**. Thin conventions #16 PASS (conventions **93**). Real review lanes — **not** mechanical `[85]`. Prior rn=1 Path A: 94 / 93 / 95 @ `8c093a1…`.

## Tips

| Role | SHA |
| --- | --- |
| **Product tip / reviewed_sha / branch tip** | `3eb652351ba2bbdeb170f7becccf9485e3a4b1bd` |
| Last 7d ancestor | `ab9d10eeb9fa6ed0d3b801b9139527cddddd7dd3` |
| Path A ancestor | `8c093a1fee8e42bd329bfea3816a5ead09b57056` |
| Jeff fail tip | `01c64969dd0137df925c5928e7f36cd65f961f69` |

`PRODUCT_TIP_ANCESTOR_OK` (tip == reviewed). Base **main**.

## PR

https://github.com/insight-lpr/platform/pull/469 — **READY** · base `main` · tip `3eb65235…` · Verify did **not** undraft / QA Ready

## Next

**Galadriel** — re-QA Ready / HITL if needed (PR already READY) → Jeff merge into **main**. Verify does **not** undraft, flip QA Ready, merge, or post Status/Fleet Board. Soft #27 tip-fix already present. Fleet env left **UP** (`:18089` / `:15441` / `:18132`); teardown on closeout / QA Ready exit is next lane. Do **not** tear DEV-2601 twin. Prior BR **42304** cited.

## Verify exit note (gates)

`phase=done` tip-fix. Soft #27 tip-fix URL present. #18 PASS · #25 N/A does_not_fire · #16 PASS · #26 main→main noted. Prior BR 42304.

# DEV-2739 Agent QA Report

**Verdict:** **PASS** → READY_FOR_VERIFY (tip-fix rn=2)  
**When:** 2026-09-29 16:06:51 CDT  
**Executor:** Pippin Agent QA (fleet Path A tip-fix)  
**PR:** https://github.com/insight-lpr/platform/pull/469 (DRAFT — **not** undrafted)  
**#27 (tip-fix refresh):** https://github.com/insight-lpr/platform/pull/469#issuecomment-5898896098  
**Prior #27 (Path A only):** https://github.com/insight-lpr/platform/pull/469#issuecomment-5891903772  
**cloud_touched:** false  
**Fleet:** LEFT UP (`clarity-qa-*-dev-2739` · `:18089` / PG `:15441` / CH `:18132`)  
**DEV-2601 twin:** untouched (`:18088`/`:15440`/`:18131`)

## Tips under test (tip-fix rn=2)
| Ref | SHA |
|-----|-----|
| tip (branch/product) | `3eb652351ba2bbdeb170f7becccf9485e3a4b1bd` — swallow nearest-given-address fetch failures |
| Last 7d Search fix | `ab9d10eeb9fa6ed0d3b801b9139527cddddd7dd3` (ancestor) |
| Path A product | `8c093a1fee8e42bd329bfea3816a5ead09b57056` (ancestor) |

Checkout: `/workspace/dev-2739-wt` @ `3eb652351ba2bbdeb170f7becccf9485e3a4b1bd` — blade SHA matches app volume.

## Suite
`NearestGivenAddressControllerTest|HitsResourceTest::test_last_7d` **8 passed / 30 assertions** on real Docker CH  
Log: `/workspace/docs/investigations/DEV-2739/phpunit-tipfix-agent-qa.log`  
(`-e APP_ENV=testing` + fleet `psql` shim; container default `local` → CSRF 419 without testing env.)

## Live AC (tip-fix focus)
Impersonate/login `brad.webb@premieradjusters.com` org **14** via `/__qa/login/2` · Hits **Open** · **New Hit + Accessible**.

| Case | Result | Evidence |
|------|--------|----------|
| **J** Last 7d + Search | **PASS** | Search writes `last_7d` bounds (`start=2026-09-22…`); yesterday **QA2739J** Sep 28 CDT visible — `04-j-last7d-search.png` |
| **M** Forced 500 → N/A | **PASS** | Playwright 500 on `/nearest-given-address`; all Distance/Nearest **N/A**; loading cleared; tip `.catch` — `05-m-forced-500-na.png` + `m-console-analysis.txt` |
| Path A type_id NULL | **PASS** | TGR0553 → 0.04 mi · 242 Lakeside Dr |
| Path A type_id=2 | **PASS** | TYP2000 → 5.32 mi · 5409 King Henry Dr |
| Zero scan geo | **PASS** | Distance/Nearest **N/A** |
| Null-geo hotlist address | **PASS** | ZZZ0000 → **N/A** |

Seed (fleet-local only, not Cloud): org 14 Premier Adjusters; brad id=2; kit 900014; hotlists 900001–900004; addresses 900101–900103; `clarity_dev_2739.hits_hot` today + yesterday QA2739J Accessible.

## #27 UI evidence
Comment: https://github.com/insight-lpr/platform/pull/469#issuecomment-5898896098  
Release assets tag `dev-2739-agent-qa-evidence-tipfix` (screenshots not git-added).  
Dir: `live-qa-evidence-tipfix/`

## Fleet-only patches (do not commit / do not push)
1. `routes/web.php` `__qa/login` allow when `force_https === false`
2. `FORCE_HTTPS=false` in fleet env
3. ASP `force_https` gate (already on tip/main)
4. Restored `vendor/laravel/ai` + livewire `dist/` into named volume (restand incompleteness)
5. CH `hotlist.first_repossessed_at` column; PG grants; `psql` under `/opt/psql` for phpunit schema load
6. Prior: vendor/tools notes — never commit

## Explicit non-actions
- No undraft · no Board · no Status posts · no Merry undraft
- No Cloud CH writes · no Fellowship bot names on Jira/GitHub
- No tear of DEV-2739 or DEV-2601 twin · no `*.insightlpr.io`

## Batons (parent must SendToAgent — executor has no SendToAgent tool)
1. **Merry** priority true — Verify
2. **Galadriel** priority true — Board/CI escalate + qa-human-guide; no Board from Pippin
3. Optional **Sam** priority false — Agent QA tip-fix PASS FYI

## Memory one-liner (Pippin)
DEV-2739 tip-fix rn=2 PASS @ 3eb65235: J last_7d + M N/A-on-500 + Path A smoke; suite 8/30; #27 https://github.com/insight-lpr/platform/pull/469#issuecomment-5898896098; fleet LEFT UP; baton Merry Verify + Galadriel.

## Machine-readable
See `qa-ac-results.json` + `agent-qa-pins.log`.

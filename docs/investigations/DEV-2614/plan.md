# DEV-2614 plan

**Approval:** blast radius approved by human:Dalton McCleery on 2026-09-29. It covers the tenant-scoped Hits CH query change plus all-org ClickHouse routing (Hits page, metric cards, Otto).
Blast-radius tags: `tenant-scope` on t1 and t2. There are no migrations, billing, auth or CH purge changes.

## Approach

The whole fix is in the shared builder `app/Support/Hits/HitsListQuery.php` plus the CH `Hit` model. ListHits, the metric cards, Otto HitSearchTool and InvestigationDataController inherit it unedited.

1. **Routing (AC-1).** `shouldUseClickhouse()` = `config('app.experimental.clickhouse.hits', false) || session()->has('admin_impersonator')`. This is the DEV-2591 idiom (ListScans.php:306-309). Pennant is out of routing.
2. **Prune (AC-2/3/5).** New `Hit::scopeForHotlistOrgs`, mirroring `Scan::scopeForOrgs` (Scan.php:53-62): int-cast, unique, empty set -> `0 = 1`. It is chained before the kept `forKits`. The set is `{user org} ∪ Org::stagingOrgIds()` (reused unchanged, 2h cache) `∪` silent_hit_generation org ids. The silent ids are a private static in HitsListQuery, cached globally for **10 min**: 729k orgs, 1 silent org, 10s page poll. The prune is AND-only, so it can never widen beyond PG. No FINAL, no forOrg/forKitOrg.
3. **AC-7 'New Hit' parity.** CH `scopeForDispositions` (Hit.php:141-160) keeps the literal `'New Hit'` in the IN-list when NEW_HIT is selected, which matches PG (app/Models/Hit.php:474). Scout's `scopeValidDisposition` is rewritten as a direct `whereRaw` that emits the **byte-identical** SQL it emits today (`(disposition IS NULL OR disposition IN ('Valid'))`), and a test pins it.
   - Caller audit: CH `forDispositions` has exactly two callers, Hit.php:68 (Scout, pinned) and HitsResource.php:429 (fixed).
   - HitSearchTool.php:244 is the **PG** branch. Its CH branch (:239) already uses `actionableDisposition()`, which includes the literal, so Otto only needs a pin.
   - MissedOpportunityResource.php:278 runs on MissedOpportunity models, which have their own scopes. Unaffected.

## DAG (serial; shared test file forces t1 -> t2)

| id | title | files | depends | complexity | test_filter |
|---|---|---|---|---|---|
| t1 | Add `forHotlistOrgs`; CH `forDispositions` 'New Hit' parity; pin `validDisposition` SQL | app/Clickhouse/Models/Hit.php, tests/Filament/Resources/ClickhouseHitQueryShapeTest.php (new, CH-free) | none | complex | `ClickhouseHitQueryShapeTest\|ClarityScoutDashboardControllerTest\|ClarityScoutMapControllerTest\|ClarityScoutBirdsEyeControllerTest` |
| t2 | Config routing + hotlist_org_id prune (**must ship together**) | app/Support/Hits/HitsListQuery.php, tests/Filament/Resources/ClickhouseRoutingTest.php, ClickhouseHitQueryShapeTest.php, tests/Feature/Otto/OttoSearchToolsTest.php | t1 | complex | `ClickhouseRoutingTest\|ClickhouseHitQueryShapeTest\|OttoSearchToolsTest` |
| t3 | Live-CH row-set test (Staging Live + silent org + literal 'New Hit') | tests/Filament/Resources/HitsResourceClickhouseRowSetTest.php (new, RequiresClickhouse) | t2 | simple | `HitsResourceClickhouseRowSetTest\|HitsResourceMetricCardsClickhouseTest` |

### Tests
- **CH-free** (runs in CI without CH): `ClickhouseRoutingTest`, `ClickhouseHitQueryShapeTest`, `OttoSearchToolsTest`.
  - `ClickhouseRoutingTest` (extended): config on/off, Pro-independent, impersonator, deactivated `clickhouse-hits` Pennant row ignored, `'clickhouse:hits'` not in `FeatureEnum::slugs()`. The stale "ListHits not covered" docblock paragraph goes.
  - `ClickhouseHitQueryShapeTest` (new): scope SQL, then real `baseQuery` hot and cold. Checks `hotlist_org_id in` (user + staging + silent), `kit_id in`, `_peerdb_is_deleted`, no `final`, `0 = 1` on empty, no unrelated org, and that PG SQL is unchanged.
  - `OttoSearchToolsTest` (extended): CH-mode sibling of `test_hits_list_query_is_kit_scoped`, plus a pin that the CH `constrainActionable` SQL contains the literal `'New Hit'`.
- **RequiresClickhouse**, in its own class: `HitsResourceClickhouseRowSetTest`. It routes via config, not the impersonator. Expected present: L own, S/Z via an approved staging org, S via a silent org, and a literal-'New Hit' row. Expected absent: a foreign-kit hit, an out-of-set hotlist org (the documented gap), O, NICB and Invalid. The cards must count the S/Z rows.
- Existing `HitsResourceMetricCardsClickhouseTest` seeds `hotlist_org_id = user org`, so it stays green under the prune. No other CH-seeded test goes through HitsListQuery (grep).

## AC coverage

| AC | Subtasks | How |
|---|---|---|
| AC-1 routing | t2, t3 | config + impersonator; routing suite; t3 exercises config end to end |
| AC-2 hotlist_org_id | t1, t2 | scope + wiring; shape test on both tables |
| AC-3 Staging Live | t2, t3 | IN-list assertion; live S/Z rows; human QA aggregate |
| AC-4 parity/doc | t3 + record | row set pinned; difference matrix in plan.json -> PR/build record |
| AC-5 no FINAL | t1, t2 | `final` absent from scope and baseQuery SQL |
| AC-6 prod flag | ship gate | confirmed true, left on |
| No-widen (derived) | t1-t3 | AND-only, `0 = 1`, foreign rows absent |
| Otto on CH (derived) | t2 | inherits baseQuery; CH-mode Otto test |
| AC-7 'New Hit' | t1, t2, t3 | CH scope parity; Scout SQL pin; Otto pin; live row |
| AC-8 after DEV-2739 | ship gate | merge-time gate |

## Ship gates (not code)
1. **AC-6:** prod `CLICKHOUSE_HITS_ENABLED=true`, confirmed and left on. Deploy flips every org and Otto to CH.
2. **AC-8:** merge/deploy only after DEV-2739's Path B distance fix is live.
3. **Q3 (QA):** read-only 30d drift measurement for one org: hits on its kits whose hotlist_org_id falls outside the set.
4. **Q5 (QA):** read-only prod `EXPLAIN indexes=1` / read_rows on hits_cold before vs after, interleaved. This also confirms the prod sort key.
5. **AC-3/AC-4 (QA):** human read-only PG-vs-CH aggregate for a Staging-Live-bearing org, with differences written up.

## Sizing
6 files (2 app + 4 test) and 2 concerns. **No split.** Routing and prune are a hard stop and ship together. Because the prod flag is on, shipping them without AC-7 would regress the 'New Hit' filter for every org. `app/Models/Org.php` is not touched.

## Not done (document only / out of scope)
- Other hit types F/M/A/P/W, duplicates (no FINAL, no dedup, Otto hydration crowding), hotlist_status lag, join gaps (measured tiny), distance columns (gated by AC-8), kit_org_id scan-time vs current.
- Moved-kit historical widening, Org::stagingOrgIds() changes, ProTierEnum/Pennant define cleanup, chlocal MV/DDL, a hit_created_at bound on Otto HitSearchTool, `.env.example`.
- No okf/docs edits in flight. Queued post-approval:
  - okf/concepts/hit.md:66
  - feature-flags.md:36-37
  - hit-address-distance.md:52,98
  - otto.md
  - docs/plans/otto.md:160
  - HitsResourceMetricCardsClickhouseTest.php:22 docblock wording
  - a note on the CH 'New Hit' parity vs Scout's rule

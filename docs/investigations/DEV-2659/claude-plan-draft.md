Read-only draft — I did not write any files. Plan below.

---

# DEV-2659 — Independent Implement Plan (draft #23)

**Base:** worktree tip `cdb90f8b`. Read from source, not from `plan.md`.

## 1. Read surface

Target is `clarity.billing_claim_events_v` (`clickhouse-schema.sql:85`), a plain view over `clarity.billing_claim_events` (`:18`) that already applies `FINAL WHERE _peerdb_is_deleted = 0` and **projects `_peerdb_is_deleted` out**. Table is `ReplacingMergeTree(_version, _peerdb_is_deleted)`, `ORDER BY (event_date, id)`, `PARTITION BY toYear(event_date)`.

Expression-by-expression replacement (both classes):

| Today | On the view |
| --- | --- |
| `ak.org_id` via `INNER JOIN public_api_keys FINAL` | `org_id` → alias `forwarder_org_id` |
| `JSONExtractInt(be.additional,'claimed_by_org_id')` | `agent_org_id` |
| `upper(trimBoth(JSONExtractString(be.additional,'vin')))` | `vin` (already normalised by the MV) |
| `JSONExtractInt(be.additional,'hotlist_id')` | `hotlist_id` |
| `toStartOfMonth(toDate(fromUnixTimestamp(be.ts),'UTC'))` | `toStartOfMonth(event_date)` |
| `toDate(fromUnixTimestamp(be.ts),'UTC')` (repos `claim_date`) | `event_date` |
| `be._peerdb_is_deleted = 0` | *(in the view — not expressible by us)* |
| the two `position(coalesce(…),'insightlpr') = 0` predicates | `is_internal = 0` |
| `be.event_type = {event_type}` | **keep** |
| `be.ts BETWEEN {f} AND {t}` | **keep**, plus a new `event_date` bound |

**Window bound.** Add `event_date BETWEEN {d_from} AND {d_to}` as PHP-computed `toDate('Y-m-d')` literals, mirroring the `{floor}` idiom already in `LprRepossessionsQuery.php:132` rather than relying on ClickHouse folding `toDate(fromUnixTimestamp(const),'UTC')`. It is strictly implied by the `ts` range (the MV defines `event_date` as exactly that expression), so it is answer-neutral, and it is the only reason the swap is cheap: `event_date` is the leading sort key, where `public_billing_events` is `ORDER BY id` and prunes nothing. Do **not** drop `ts BETWEEN` — `today`/`last_7d` are sub-day windows.

## 2. Orgs path — delete, confirmed from code

`orgs` is referenced **only** by the two needle joins in each class, and its only projected column `n` **only** by the two `position(...)` predicates:

- `UniqueLprCaseClaimsQuery.php:252-256` (CTE) → `:275-278` (joins + predicates). No other reader.
- `LprRepossessionsQuery.php:167-171` (CTE) → `:191-194`. No other reader.

So: delete the CTE, both `LEFT JOIN orgs`, both `position()` predicates, the `public_api_keys` subquery join, and every `JSONExtract*`. Also collapse `kept` into `claims` — `kept`'s only remaining content is the `{org} = 0 OR …` predicate, which belongs in the view's `WHERE` where it can push down.

Consequence: `{db}` drops to **zero** uses in `UniqueLprCaseClaimsQuery` → delete `zodDb()` (`:412-417`) and its `strtr` key. `clarityDb()` stays (now serves both the view and `hits_cold`). In `LprRepossessionsQuery`, `zodDb()` **stays** — `public_hotlist` and `public_hotlist_status_log` are still zod reads (DEV-2657 / DEV-2660).

Neither class reads `clarity.orgs` today, so there is nothing to remove there. `clarity.orgs` (`:89`) is `type IN (1,2)` with *no* `FINAL` and *no* `_peerdb_is_deleted` filter; the MV computes `is_internal` from `zod.public_orgs FINAL` over **all** types (`:102`), which matches today's query exactly. Do not adopt `clarity.orgs`.

## 3. Semantics that change — must be docblocked, not inherited silently

These are properties of the MV, not of our SQL, and none are in `plan.md`:

1. **`is_internal` is frozen at ingest.** The MV's watermarks are `_peerdb_synced_at` on the event and `_peerdb_api_key_synced_at` on the api key (`:102`); there is no `changed_org_ids` path. An org renamed into or out of the `insightlpr` needle **never** re-flags its historical claims. Today's query recomputes from live `public_orgs FINAL` on every read. Reconciliation at 15128/14288/11975 is unaffected, but the metric's meaning changes and the drift is permanent absent a manual re-append. Record it; raise it on the ticket.
2. **Forwarder `org_id` has an 8-day repair window.** The api-key catch-up arm is `… AND toDate(fromUnixTimestamp(be.ts),'UTC') >= today() - 8`. An `api_keys.org_id` correction older than 8 days never reaches the fact table.
3. **Tombstone discipline moves into the view, and we lose the ability to express it.** `_peerdb_is_deleted` is not a column on `_v`. `LprRepossessionsQuery`'s "per-table CDC discipline is deliberately asymmetric / only 1-arg engine of the 25 mirrors" paragraph (`:60-65`) becomes **false** for the billing leg — the new table is 2-arg, so `FINAL` does drop tombstones. Rewrite that paragraph; do not merely trim it.
4. **The view's unconditional `FINAL` disables PREWHERE movement** (`optimize_move_to_prewhere_if_final` defaults to 0) for `is_internal` / `event_type` / `{org}`. Almost certainly irrelevant at this table's size — but it is a measurable, and a trailing `SETTINGS optimize_move_to_prewhere_if_final = 1` on our own `SELECT` is the lever if Docker `X-ClickHouse-Summary` says otherwise. Not a planned change.

## 4. Shared dedup

`plan.md` shares the triple plus the repos `deduped` CTE. I'd cut it differently.

The triple is a 3-word string; the thing that actually drifts — and whose drift is silent and severe — is the **filter set**. If one class loses `is_internal = 0`, internal claims re-enter one card and not the other, and every existing test still passes.

Propose `app/Clickhouse/Queries/BillingClaimSource.php`, static, no constructor, three members:

- `const TRIPLE = '(forwarder_org_id, agent_org_id, vin)'` — one place for Item 5/AC-7 to later cut to two columns.
- `from(string $clarityDb): string` → `"{$clarityDb}.billing_claim_events_v"`.
- `whereSql(): string` → the placeholder-bearing predicate (`is_internal = 0 AND event_type = {event_type} AND ts BETWEEN {f} AND {t} AND event_date BETWEEN {d_from} AND {d_to} AND ({org} = 0 OR forwarder_org_id = {org} OR agent_org_id = {org})`), substituted by each class's existing `strtr` — consistent with how `sql()`/`buildSql()` already split emit from substitute.

Each class keeps its own projection list (claims needs `bucket`/`ts`/`hotlist_id`; repos needs `claim_date`). **Do not** move repos' `deduped` CTE into the helper — one caller is not shared code.

Cross-class agreement is already pinned by `LprRepossessionsQueryTest::test_deduped_stage_agrees_with_unique_lpr_case_claims_query` (`:465`). Add one cheap static assertion that both rendered SQLs contain `is_internal = 0`.

**Hard constraint on SQL prose:** `UniqueScansToCaseClaimsQueryTest::test_kept_expansion_counts_are_the_true_reflection_measured_floor` (`:384-398`) does `substr_count` on the *un-stripped* inlined SQL. The literal `billing_claim_events_v` must not appear in any SQL comment, or the reference count inflates. (Today's SQL gets away with this by never spelling `public_billing_events` in prose.) Counts stay **1 / 1 / 2 / 2** after the change — `oc` is still referenced twice.

## 5. Subtasks

**t0 (blocking, do first) — `tests/Concerns/RequiresClickhouse.php`.** Add `clarity.billing_claim_events` to `CLICKHOUSE_TABLES_TO_TRUNCATE` (`:16-43`). It is absent today, and the trait's own comment states the consequence: "A table a test seeds but this list omits leaks rows into every later test class in the run." Three test classes will seed claims into the same July-2026 window and assert exact platform-wide totals (e.g. `test_either_side_org_axis` expects 3). Without t0 the suite is order-dependent. `plan.md` omits this entirely.

**t1 — `BillingClaimSource`** (§4) + `tests/Unit/Clickhouse/BillingClaimSourceTest.php`.

**t2 — `UniqueLprCaseClaimsQuery`** (depends t1). Rewrite `sql()`'s base CTE; delete `orgs`/`kept`/`zodDb()`; `claimsExtra` becomes plain `hotlist_id, ts` columns. Leave `preClaimSql()`'s `hits_cold` join, `scanned_at > toDateTime(0)`, the `oc` prune, and `h.hotlist_id != 0` **untouched**. Rewrite the class docblock: the read surface, the single-pass rationale (now `event_date` pruning, not just "one scan"), and the §3 items 1–3.

**t3 — `LprRepossessionsQuery`** (depends t1, parallel with t2). Same claims rewrite; `deduped` CTE now groups on `event_date AS claim_date`. Outer `SELECT`, both hotlist joins, `d.agent_org_id != 0`, `h.id != 0` and `{floor}` unchanged. Rewrite the CDC-asymmetry paragraph per §3.3.

**t4 — tests.** Three classes, all switching `seedClaim()` to insert into `clarity.billing_claim_events` directly (the view is not an insert target; it is a plain view so rows are visible immediately — no refresh):

- `UniqueLprCaseClaimsQueryTest` — `seedOrg`/`seedApiKey` become vestigial; add `bool $internal = false` → `is_internal`. **Coverage loss to state plainly:** `is_internal` is one flag, so `test_insightlpr_excluded_on_forwarder_side` and `..._on_agent_side` are no longer distinguishable at our layer, and `test_titlemax_not_excluded` becomes a tautology. Collapse to `test_internal_claims_are_excluded` / `test_non_internal_claims_are_kept` and say so in the PR — do not pretend the old coverage survived.
- `LprRepossessionsQueryTest` — same, plus rewrite `test_tombstoned_billing_event_is_excluded_even_though_final_would_keep_it` (`:342`): seed `_peerdb_is_deleted = 1` on the fact table and keep the assertion, but its docblock (1-arg engine / PG-028) is now false. Retarget it as a *view-contract* test: it guards against someone pointing the query at the base table instead of `_v`. Hotlist/status-log fixtures unchanged.
- `UniqueScansToCaseClaimsQueryTest` — `seedClaim` rewrite; flip the `substr_count` needle at `:391` to `billing_claim_events_v`; assertions stay 1/1/2/2. `plan.md` does not mention this class at all.

**t5 — new coverage for what left the repo.** `tests/Unit/Clickhouse/BillingClaimEventsSchemaContractTest.php`: parse `database/schema/clickhouse-schema.sql` and assert (a) `billing_claim_events_v` applies `FINAL` and `_peerdb_is_deleted = 0` and does not project `_peerdb_is_deleted`; (b) the MV's `is_internal` expression tests the `insightlpr` needle on **both** `fo.n` and `ao.n`. The needle is now Cloud-side with zero repo coverage; the committed dump is the only record, and this makes an upstream redefinition visible in a diff.

`CompanyMetricsCacheScopeTest` (`:21-31`) and `CardQuerySqlFidelityTest` (`:145-150`) stay green as written: `billing_claim_events_v` and the three DEV- tokens remain in the files, no `shared*` method is added to the claims class, and every `*Sql()` builder keeps its name.

**Files:** 2 modified queries, 1 new query helper, 1 modified test trait, 3 modified test classes, 2 new tests. No migration, no widget, no config, no `okf/`.

## 6. Fleet Docker ClickHouse

Verified here: `RequiresClickhouse` hard-skips unless `database.connections.clarity_clickhouse.driver === 'clickhouse'` (`:47`). A pgsql-mirror pass is exit-0 silence, not a done-signal — and per the harness's own header, the mirror is a schema bridge, not a dialect translator, so `FINAL`/`uniqExactIf` would not run anyway. Cloud stays read-only; nothing in this plan queries it.

`release-prep/SKILL.md:278-280` sets `QA_CH_CONTAINER=clarity-qa-clickhouse`, `QA_CH_HTTP_PORT=18123`, `QA_CH_DB_CLARITY_PATTERN='clarity_{wt}'`, `QA_CH_DB_ZOD_PATTERN='zod_{wt}'`, schema from `database/schema/clickhouse-schema.sql`. I did not read `up.sh` (it lives in the plugin, outside this worktree), so treat the rest as carried from `plan.md`, unverified in this draft.

**One provisioning check to run before Agent QA, which `plan.md` does not have.** Lines 85 and 102 of the schema dump embed **literal** `clarity.` / `zod.` prefixes *inside the view and MV bodies*. Under a `clarity_{wt}` / `zod_{wt}` load, unless `up.sh` rewrites those bodies, `clarity_{wt}.billing_claim_events_v` resolves `FROM clarity.billing_claim_events` — either a missing database (whole class errors) or, worse, a leftover literal `clarity` database shared across worktrees (silent cross-contamination). No existing test exercises a ClickHouse view, so this is untested ground either way. Decisive one-liner after `up.sh`:

```
SHOW CREATE VIEW clarity_{wt}.billing_claim_events_v
```

and confirm the body names `clarity_{wt}`. If it does not, the ship options are literal-DB QA (which I'd resist — it re-opens the DEV-2470 de-isolation problem) or a DDL rewrite in provisioning. Resolve this at Provision, not mid-QA.

Seed `clarity.billing_claim_events` (not `_v`). Keep an `agent_org_id = 0` row and a hotlist `id = 0` row so the two sentinel guards still fail loudly if removed. AC-2/AC-6 targets (Jul 2026 UTC, platform-wide: 15128 / 14288 / 11975) stay ticket-level; Docker proves the seeded arithmetic plus the absence of `public_billing_events`, `JSONExtract`, `public_api_keys`, and any `orgs` CTE from the rendered SQL.

## 7. Non-goals

Item 5 / AC-7 (dropping `forwarder_org_id`) — deferred; the triple stays three columns. No `ClickhouseQuery` inheritance for either class (their no-try/catch guarantee is the reason they stand alone). No cache retune — `heldFlexible`, both shared repos keys, and `CLOSED_MONTH_SETTLE_FULL_SECONDS` untouched. No change to the `hits_cold` pre-claim leg or any new production rate claim. Hotlist and status-log legs stay on zod (DEV-2657 / DEV-2660). No Cloud DDL, no Cloud re-measurement. No `okf/` edits — `okf/concepts/billing-events.md` still describes `zod.public_billing_events` and is queued for the post-approval pass.

## 8. Disagreements with `docs/investigations/DEV-2659/plan.md`

**Converge:** read surface and `is_internal = 0`; delete `orgs` + needle joins + `kept`; keep `ts BETWEEN` and add an `event_date` bound; alias `org_id` → `forwarder_org_id`; keep all three sentinels and no `join_use_nulls = 1`; single-pass with `oc` referenced twice; AC-7 out; no base class; no cache change; Docker CH is the done-signal, not pgsql.

**Diverge:**

1. **Missing `RequiresClickhouse` truncate entry.** `clarity.billing_claim_events` is not in the list. Order-dependent test failures. Not in `plan.md`; I make it t0.
2. **Missing test class.** `UniqueScansToCaseClaimsQueryTest` is unmentioned, yet it hard-codes `'public_billing_events'` as the reference-count needle (`:391`) and will fail. Also imposes the "no `billing_claim_events_v` in SQL comments" constraint, which nothing in `plan.md` records.
3. **Frozen-at-write `is_internal` / 8-day api-key window.** Not mentioned. These are the real semantic cost of denormalising and they must be docblocked and raised, not inherited.
4. **`_peerdb_is_deleted` becomes unexpressible.** `plan.md` doesn't note that the view projects it out, so the repos tombstone test and the class's CDC-asymmetry paragraph both need rewriting rather than trimming — its "1-arg engine" reasoning becomes false against a 2-arg table.
5. **Helper cut.** `plan.md` shares the triple + repos' `deduped` CTE; I'd share the triple + the WHERE predicate and leave `deduped` with its single caller. The WHERE is what drifts dangerously; a one-caller CTE in a helper is surface without protection. Its proposed unit test ("asserts `uniqExact` is the function name in the claims call site contract") also asserts a property of a caller from inside the helper's own test — that belongs on the rendered SQL.
6. **AC-3 wording is factually wrong.** "AC-3 is met by deleting `clarity.orgs` from these queries" — neither class ever reads `clarity.orgs`; they read `zod.public_orgs`. The correct framing is a negative constraint (do not adopt it), which the decision table does get right.
7. **Provisioning hazard absent.** Literal `clarity.`/`zod.` prefixes inside the view/MV bodies vs. `clarity_{wt}`/`zod_{wt}`. This is the most likely thing to derail Agent QA and it is cheap to settle up front.
8. **`event_date` bound left unspecified.** I'd pin it to PHP-computed `toDate('Y-m-d')` literals, citing the existing `{floor}` idiom (`LprRepossessionsQuery.php:132`), rather than leaving folding to chance.
9. **Coverage-loss framing.** `plan.md` says "no fan-out fixture" and stops. It should say outright that the forwarder-side / agent-side / TitleMax tests degrade to fixture tautologies and that the needle logic now lives in Cloud with no repo test — which is why I add t5.

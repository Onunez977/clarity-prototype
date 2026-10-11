I've read the artifacts, the canon page, and the cited code on this branch. Here is my independent plan.

---

# DEV-2739 — Independent implement plan (co-pilot draft)

**Stance:** the gate's classification is still open. Nothing below names a root cause. Every measure step is read-only; Cloud ClickHouse is `SELECT` only.

Where this differs from `plan.md`: it adds a zero-cost browser observation **before** any CH query, it stratifies the CH measure by `hotlist_status` so the sharpest hypothesis is falsifiable in one pass, it adds a mirror-freshness control so "0 rows for org 14" can be told apart from "table stale everywhere", and it flags a fix-shape trap that `plan.md` does not (see §5.3).

## 1. What the code already constrains (read, not assumed)

- Impersonation → `shouldUseClickhouse()` true (`ListHits.php:121-125`) → `buildClickhouseQuery()` (`:127-169`), which selects `lat`, `lng`, `hotlist_id` but **not** the two aliases → `HitsResource.php:196-213` / `222-246` fall through to the blades → `POST /nearest-given-address` (`routes/web.php:96`) → `NearestGivenAddressController` → `clarity.recovery_address FINAL`, `id = hotlist_id`, `geo.1 != 0 AND geo.2 != 0`.
- The feature gate is **not** a suspect: `PennantServiceProvider.php:40` defines `FeatureEnum::HIT_ADDRESS_DISTANCE->slug()` and every call site checks the same `->slug()`. Consistent. (`clickhouse:hits` at `:123` is the separate known track — it does not affect an impersonated session.)
- `public.recovery_address` is `hotlist_address ⋈ address WHERE a.type_id = 2`, `row_number = 1` (`vendor/.../zod-schema.sql:18144-18167`). `okf/concepts/hotlist.md:56` calls `type_id = 2` the repo / address-of-recovery. The locked reproduce is **Status = Open**. That is the sharpest falsifiable hypothesis this measure must test — it is *not* a finding yet.

**New inference worth carrying (cited, cheap):** the two blades use *different* truthiness. Distance renders a value on `res.data?.distance_miles !== undefined` (`distance-from-given.blade.php:19`); Address renders on `res.data?.address` (`nearest-given-address.blade.php:20`). A returned row with non-zero geo but an empty `formatted_address` would show **miles + N/A**, not N/A + N/A. Q1 locks *both* columns N/A on every row, which is consistent only with: no POST fired, the POST failed, or the POST returned `[]`. It is **not** consistent with "rows exist but the address text is blank". That eliminates one branch before any CH byte is read.

## 2. M0 — Browser-only classifier (run first; costs nothing)

Re-open the locked reproduce (Org 14, impersonation, same filters). Do not change filters.

1. **DevTools → Network**, filter `nearest-given-address`. Record for ~10 rows: request fired at all? status code? response body?
   - **No requests at all** → the formatter short-circuited (`hotlist_id`/`lat`/`lng` falsy). Class = data-on-the-hit-row (scan geo). Skip to §3 step 2 only.
   - **Non-2xx** (422 / 419 / 500) → class = request/UI layer. Go straight to §5.1. No CH measure needed.
   - **200 with `[]`** → class = source has no qualifying row. Go to §3 in full.
   - **200 with `address` + `distance_miles` and the cell still says N/A** → class = UI. Go to §5.2.
2. **Elements**: confirm which N/A rendered. Blade N/A sits inside a `<div x-data="{ distance: '', loading: true, …">`; the formatter literal is bare text with no `x-data`. This distinguishes `HitsResource.php:213/246` from the blade branch without guessing.
3. Note the request payload's `hotlist_id`, `lat`, `lng` for 5–10 rows — these are the ids the CH measure uses. No id invention.

Record the raw observations in `docs/investigations/DEV-2739/measure.md`. Not in `okf/`.

## 3. M1 — Cloud ClickHouse measure (read-only), only if M0 says `[]`

Pin the window: Org 14 viewer's `dti` timezone, 2026-09-25 local midnight → 13:54 CT, converted as `Hit::scopeForCreatedBetween` does (`convertToUtc`). Do not re-run "today" on a later day.

**Step 0 — confirm the cohort, don't assume it.** Read the impersonated user, confirm `org_id = 14`, and take `getUserKits()->pluck('id')` (read-only Postgres). The CH list is `forKits`, **not** `hotlist_org_id = 14`. If org_id ≠ 14, stop and record it.

**Step 1 — the hit rows, mirroring the list exactly** (`hits_hot`, no `FINAL`, same predicates as `buildClickhouseQuery` + the locked filters; disposition predicate copied from `Hit::scopeForDispositions:141-160`, i.e. `(disposition IS NULL OR disposition IN ('Accessible'))`):

```sql
SELECT count()                                        AS hits,
       countIf(lat = 0 OR lng = 0)                    AS zero_geo,
       countIf(lat != 0 AND lng != 0)                 AS usable_geo,
       uniqExactIf(hotlist_id, lat != 0 AND lng != 0) AS hotlists,
       uniqExactIf(hotlist_id, lat != 0 AND lng != 0) -- stratified:
FROM clarity.hits_hot
WHERE kit_id IN (…) AND integration_id != 5 AND hit_type != 'O'
  AND hotlist_status = 1
  AND (disposition IS NULL OR disposition IN ('Accessible'))
  AND hit_created_at BETWEEN … AND …
```

If `zero_geo = hits`, the class is decided here (contract N/A, formatter short-circuit) and M0 step 1 already agreed. Stop.

**Step 2 — the Path B source, as the controller reads it,** for those hotlist ids:

```sql
SELECT count()                                     AS rows,
       uniqExact(id)                               AS ids_present,
       countIf(geo.1 != 0 AND geo.2 != 0)          AS non_zero_geo,
       countIf(_peerdb_is_deleted = 1)             AS soft_deleted,
       max(_peerdb_synced_at)                      AS last_sync
FROM clarity.recovery_address FINAL
WHERE id IN (…)
```

**Step 3 — mirror-freshness control (this plan adds it).** Without it, `ids_present = 0` cannot be told apart from a stale/empty mirror:

```sql
SELECT count(), uniqExact(id), max(_peerdb_synced_at) FROM clarity.recovery_address FINAL;
```

A globally stale `last_sync` or a near-empty table is a **pipeline** class, not an Org 14 class, and lands with a different owner.

**Step 4 — status stratification (this plan adds it; it is the decisive cut).** Same kits, same day-window, drop the status filter and group:

```sql
SELECT hotlist_status, uniqExact(hotlist_id) AS hotlists
FROM clarity.hits_hot WHERE … GROUP BY hotlist_status
```

then re-run step 2 per status bucket. If Repossessed (`3`) hotlists have `recovery_address` rows and Open (`1`) hotlists do not, the all-N/A is explained by the source's `type_id = 2` grain meeting an Open-status list — **and that is a classification, not a winner**; Q3 becomes live and goes to Dalton.

**Step 5 — Path A contrast (PG, read-only), only if step 2 returns ~0 with non-zero scan geo.** Same hotlist ids, `buildPostgresQuery`'s shape (`a.geo IS NOT NULL`, no `type_id`, no `(0,0)` exclude) **broken out by `a.type_id`** and by the `(0,0)` sentinel, so the decision packet is concrete:

```sql
SELECT a.type_id,
       count(*) FILTER (WHERE a.geo IS NOT NULL)                          AS geocoded,
       count(*) FILTER (WHERE a.geo = '0101000020E6…0000'::geometry)      AS zero_sentinel,
       count(DISTINCT ha.hotlist_id)                                      AS hotlists
FROM hotlist_address ha JOIN address a ON a.id = ha.address_id
WHERE ha.hotlist_id IN (…) GROUP BY a.type_id
```

Record agreement/disagreement. **Do not pick a source.**

## 4. M2 — Classify (one row only)

| Measure result | Class | Next |
|---|---|---|
| POST never fires / rows have `lat = 0 OR lng = 0` | Contract N/A — hit-row data | Stop. Data question for Dalton (why no scan coords), no product diff. |
| POST non-2xx | Request/UI defect | §5.1 |
| POST `200 []`, mirror globally stale/empty | Pipeline/data | Stop. Owner is the CDC side, not this repo. |
| POST `200 []`, mirror healthy, **no** qualifying `recovery_address` row, Path A **would** return one for the same hotlists | Contract N/A for Path B + **proven path disagreement** | Stop. Hand Dalton the Q3 decision packet (§6). No unilateral source swap. |
| POST `200 []`, mirror healthy, `recovery_address` **has** a non-zero-geo row for that hotlist id | **Bug candidate** | §5.1 |
| POST returns values, cell still N/A | UI | §5.2 |

## 5. Fix branches — exactly one runs, and only after M2

Simple wins only. No new types, no new query class, no new table, no batching endpoint, no architecture.

**5.1 Controller / request branch** — `app/Http/Controllers/NearestGivenAddressController.php::__invoke`. Candidate misses, in order of likelihood once M0 names the status code: validation rejecting a legitimate payload (`:21-25`), the `lat == 0 || lng == 0` guard firing on a coordinate the list considers valid (`:31-33`), or the `WHERE` in `:44-47` excluding a row the measure proved is present. Fix the one the measure names. Keep `FINAL`. Keep the `geo.1/geo.2` non-zero guard unless the measure shows it is what drops a live row.

**5.2 UI branch** — `distance-from-given.blade.php:17-27`, `nearest-given-address.blade.php:18-28`, or `resources/js/app.js:64-76`. Note `nearestAddressCache` keys on `${hotlistId}-${lat}-${lng}`, so it cannot cross-contaminate rows; a rejected POST is never cached, so a retry is live. If this branch runs, `public/build` must be rebuilt and committed — and only this manifest entry re-pointed (main's committed build can be stale).

**5.3 Source-swap branch — gated on a Dalton decision, not on the measure.** If and only if Q3 resolves to Path A semantics:
- **Do not** point the controller at `zod.public_hotlist_address` / `zod.public_address` in ClickHouse. Both are `ORDER BY id`, so `WHERE hotlist_id = ?` cannot prune, and `public_address.geo` is a `Nullable(String)` EWKT needing `extract()` — a per-row, per-request full scan of a very large table (`clickhouse-schema.sql:50,60`).
- The cheap shape is a **Postgres read inside the same controller**, on the `zod` connection the `Hit` model already uses (`app/Models/Hit.php:83`), reusing Path A's SQL for one hotlist. `hotlist_address` has `UNIQUE (hotlist_id, address_id)` (`zod-schema.sql:25856`), so the lookup is indexed. One method body, no new class.
- Still out even then: altering `public.recovery_address` or `clarity.recovery_address`.

## 6. Q3 decision packet (if paths disagree)

Hand Dalton, in the ticket: per-status hotlist counts; `recovery_address` coverage for those ids; Path A coverage broken out by `type_id` and by the `(0,0)` sentinel; and the one-line consequence of each choice (Path A = any linked geocoded address including non-repo types; Path B = repo address only, which an Open order may not have yet). No recommendation baked into code before he answers.

## 7. Tests

Only if code ships. No test exists today for the controller or either formatter.

- `tests/Feature/Http/Controllers/NearestGivenAddressControllerTest.php` (`php artisan make:test --phpunit`). Pin **the branch that was wrong**, plus the contract cases that must keep returning `[]` (feature off + no impersonator; `lat`/`lng` = 0; no qualifying row). Use `createUser()` / `makeOrgPro()` from `tests/TestCase.php`.
- Put the CH-reading case behind `RequiresClickhouse` **at method level, not class level** — a class-level trait silently skips the auth/gate assertions too and exits 0 locally.
- If 5.2 ships, add a Filament table assertion that the record with truthy `hotlist_id`/`lat`/`lng` renders the blade rather than the literal `N/A`.
- Run only the touched file: `php artisan test --compact --filter=NearestGivenAddress`. `composer format` before finishing.

## 8. Regression org (AC4)

Falls out of §3 step 4 for free: pick an org, not 14, whose hits in the same window have a `hotlist_id` with a non-zero-geo `recovery_address` row, and confirm its Hits list shows miles + an address **before** and **after** the change. Read-only. Not a DEV-1611 reopen.

## 9. Do not touch

`public.recovery_address` (vendor MV) · `clarity.recovery_address` schema · `hotlist_status_aggregate` · Scout's open-address book (DEV-2163 / DEV-2386) · Path A's missing `(0,0)` exclusion (unless a measure shows it changes these rows) · the `clickhouse:hits` literal (separate track; irrelevant under impersonation) · Hits routing, metric cards, or the CH hits tables · `okf/` while this is in flight — queue the wiki update for after approval, then `php scripts/okf-lint.php`.

## 10. Order of operations

M0 (browser, free) → M1 (Cloud CH read-only, PG contrast only if step 2 is ~0) → M2 classify → **stop or** one fix branch → test → regression org → post-merge wiki pass.

M0 is the change I would most argue for: three of the six classes in §4 resolve from one page load, before spending a Cloud CH query.

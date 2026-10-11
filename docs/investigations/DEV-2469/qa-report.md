# DEV-2469 — QA against real ClickHouse

**Env:** local Docker ClickHouse 25.8 on `127.0.0.1:18123`, LITERAL `clarity` / `zod` databases.
**Viewer:** user 1, org 2, timezone **America/Chicago** (deliberately non-UTC — the UTC-axis fix
is invisible against a UTC viewer). Org 2 has no COMPANY_METRICS entitlement.
**Seed:** `.context/qa-ch-seed.sql` (reproducible).

## ⚠ Environment defect found and fixed before QA ran

`.env` pointed ClickHouse at **production Cloud** (`zd69ju2h8d...clickhouse.cloud:8443`) with
`CLICKHOUSE_DATABASE_CLARITY=zod_claude_dev_2469`, a per-worktree name that does not exist there.
A QA seed WRITES. Repointed to the local container with literal db names before anything ran.
Prior `.env` backed up under `/tmp/claude-501/env.backup.*`.

Also found: the container's leftover seeds came from two different tickets and did not join —
billing events referenced agent org 9902 / `QAVIN*`, the hotlist rows were org 340/341 /
`QATESTVIN*`. That is why `db_repos` was 0 before seeding, and it would have read as a card bug.

## Both real queries executed

| leg | result |
| --- | --- |
| `LprRepossessionsQuery::window(2, Sep 2026)` | `{deduped: 8, db_repos: 8}` |
| `LprRepossessionsQuery::monthlySeries(2)` | `2026-09-01 => {deduped: 8, db_repos: 8}` |
| `ScanPlateStatsBatchQuery::handle(org 2, UTC)` | `{"2026-09": 40}` |
| `ScanPlateStatsBatchQuery::handleRange(Sep)` | `totals[0] = 40` |

The seed deliberately writes **120 scan rows carrying only 40 distinct plates**, so a
`count()`-instead-of-`uniqExact` regression would render 15.0 rather than 5.0.

## Card rendered end-to-end — all four filter arms

| filter | LprRepossessions | this card | expected | sibling after |
| --- | --- | --- | --- | --- |
| today | 1 | **6.0** | 6 plates / 1 repo | 1 — uncorrupted |
| last_7d | 1 | **40.0** | 40 plates / 1 repo | 1 — uncorrupted |
| this_month | 8 | **5.0** | 40 plates / 8 repos | 8 — uncorrupted |
| last_month | 0 | **0.0** | no August data | 0 — uncorrupted |

Sparkline: 13 points, last = 5. Labels UTC-anchored (`Aug '26`, `Sep '26`) for a Chicago viewer.
Description names the unit: `Sep '26: 5.0 | Aug '26: 0.0 scans per LPR repo`.

## The RN3 regression is gone, verified live

Rendering BOTH cards in one process (real dashboard order: LprRepossessions slot 4, this card
slot 8) and re-reading the sibling afterwards shows no corruption in any arm. Under the RN3 bug
this card read 0.0 and the sibling could render 1.

## Round trips (DEV-2406 AC)

Measured from `system.query_log`: **cold render = exactly 2** (one numerator batch, one
denominator series), **warm render = 0**. Matches the docblock claim. No per-month fan-out.

## Pro gate

`canViewCardData()` returns **locked** for org 2 (no COMPANY_METRICS). `lockedStat()` renders the
placeholder with a NULL chart and issues no query.

## ⚠ What this QA does NOT establish

Measured envelope here: numerator 229 read_rows / 4.11 KiB / 6.28 MiB peak; denominator 89
read_rows / 3.75 KiB / 7.30 MiB peak. Production is **60.7M rows / 2.44 GB peak** for the
numerator and **584M rows / 2.79 GB peak** for the denominator.

**So this is a FUNCTIONAL pass, not a scale pass.** The five residual findings from review — in
particular that the UTC numerator does not share with `UniqueScanCounts` for non-UTC viewers
(~2x the unguarded `scans_cold` axis), the missing cold guard, and the missing failure marker —
are untouched by this run and remain open.

---

## Browser pass (agent-browser, real Chrome, live dashboard)

`qa-prep` was **deliberately not run.** `.context/` held **DEV-2486's** plan, guide and
screenshots from 2026-09-04 (different branch, base, DB and site) — the gate correctly refused
it, and those artifacts are now archived under `.context/_archive-DEV-2486/`. Re-provisioning
would have re-pointed ClickHouse (its `up.sh` sets a per-worktree `CLICKHOUSE_DATABASE_CLARITY`,
which breaks the ~15 query classes that hardcode the `clarity.` prefix) and destroyed an
environment already verified end-to-end. The browser pass was driven directly against it with
the same `agent-browser` CLI the lanes use.

Login: `/__qa/login/1`. Viewer: user 1, org 2, `America/Chicago`, Administrator + Clarity Pro Plus.

### Every filter arm, read from the live DOM

| filter | Unique Scan Counts | LPR Repossessions | this card | check |
| --- | --- | --- | --- | --- |
| Today | 6 | 1 | **6.0** | 6 / 1 |
| Last 7d | 40 | 1 | **40.0** | 40 / 1 |
| This Month | 40 | 8 | **5.0** | 40 / 8 |
| Last Month | 0 | 0 | **0.0** | zero-denominator guard |

The numerator and denominator are rendered by their OWN separate cards, so the ratio is
cross-checked against independently-computed operands in all four arms — stronger evidence than
the in-process run.

### Cross-card corruption: not reproducible in a browser

All three cards render distinct, correct values simultaneously in every arm. The RN3 regression
(shared key, two payload shapes) would have shown as this card reading 0.0 or LprRepossessions
reading 1.

### Visual cross-checks from the grid

- `Scan Count 132` vs `Unique Scan Counts 40` — `uniqExact(ocr)` visibly collapsing the 132
  seeded rows to 40 distinct plates.
- `Unique Scans to Case Claims 37.5%` renders beside `Unique Scans to LPR Repossession 5.0` —
  a percentage and a ratio side by side. This is the visual justification for decision 1
  (literal ratio, not a percentage): the two cards read as different quantities, as intended.
- `Unique Scans to Repo 0` is still the DEV-2470 stub — correct, that ticket is blocked.
- Sparkline renders as an SVG on the card.

Screenshots: `.context/qa-screenshots/dashboard-this-month.png`,
`card-unique-scans-to-lpr-repo.png`.

### Laravel log

**Zero** error-level lines dated 2026-09-12. The 8 `ERROR` entries in the log are all from
2026-09-11 21:10 — a prior session seeding Postgres `scans` against a `simplified_geom` trigger,
unrelated to this card and predating this run.

### Not covered by the browser pass

Card expansion interaction, and a JS-console error collector (the collector must be installed
before page load; the pass read the DOM after load instead). Neither blocks — the grid rendered
complete with `readyState=complete` and no failed requests.

# DEV-2904 Ultra-Plan — Opus primary draft (read-only)

You are a **read-only co-pilot planner** for ticket DEV-2904. Your stdout becomes `docs/investigations/DEV-2904/claude-plan-draft.md`.

## Role and tools

- Plan only. **Do not invent** product behavior, files, columns, jobs, or AC that prep-context / Jira / First Glance do not support.
- **Tools allowed: Read, Grep, Glob only.** No Bash, no Edit, no Write, no product PHP/Vue edits, no git, no DB/CH/Herd/fleet mutations.
- Re-read `docs/investigations/DEV-2904/prep-context.json` (gate CLEAN) and cite live surfaces before proposing touches.
- Prefer the CLEAN gate. Prep already cited field sources. **Only park** on a real new Question Gate with concrete questions — otherwise leave Open questions empty. Blank Repo Agent Name is allowed by AC ("when available") — not a QG.

## Ticket (authoritative)

Jira: https://insightlpr-team.atlassian.net/browse/DEV-2904  
First Glance **comment 42732** (Ready). Branch path: **main→main**. Draft PR: https://github.com/insight-lpr/platform/pull/501 (base `main`, tip `8f8c055f`).

**AC:**

1. Export button (CSV locked — house Filament `Exporter` + `ExportFormat::Csv`) on the LPR Repossessions bar chart.
2. One row per VIN that makes up the chart data — Prep: one row per `(forwarder_org_id, agent_org_id, vin)` triple in headline `db_repos` (not distinct VIN; not sum of 13 bars).
3. Columns when available: VIN, Plate, RDN Case Number, Claimed By, Repo Agent Name.
4. Respects current chart filters (date toggle + `auth()->user()->org_id`; **no client picker** on this card — do not add one).
5. Filename e.g. `lpr-repossession-export-YYYY-MM-DD.csv`.

**Hard stops:** Do not change aggregate chart / funnel semantics (`db_repos` vs `deduped`). Do not invent optional column sources. Cloud CH READ-ONLY. Do not widen to UniqueLprCaseClaims / other cards. No scout base.

## Preflight framing

- **#17** — Neutral attribution on any text that could ship to Jira/GitHub later.
- **#23** — This run is the **Opus primary draft only** (parent will CloudAgent-review next). Do not write `plan.json`.
- **#24** — Smallest change; reuse existing Exporter / ExportAction patterns; **no new abstractions** unless prep proves none exist.
- **#25** — Parity: export row count == chart `db_repos` same filters (new listing path vs existing aggregate — prove plan for parity tests).
- **#26** — main→main.
- **#27** — UI screenshots later for Agent QA (note only).
- **#18** — Agent QA/Verify need real fleet Docker CH (provision note).

## What you must re-read / cite

From prep-context + code:

- `app/Filament/Widgets/Dashboard/LprRepossessions.php` — expanded chart is the export button home; ChartWidget has no HasActions (gap).
- `app/Clickhouse/Queries/LprRepossessionsQuery.php` — `window` / `monthlySeries` counts only; `db_repos` = `uniqExactIf((forwarder_org_id, agent_org_id, vin), backyard)`; do not change aggregate SELECT.
- House exporters: `app/Filament/Exports/*Exporter.php` (esp. RecoveryLeaderboardExporter) + call site `LeaderboardTableWidget` ExportAction (`s3`, `{high}`, chunkSize).
- Field sources (prep cited — do not invent):
  - VIN: billing_claim_events_v / deduped.vin
  - Plate: zod.public_hotlist.plate on status-3 join (alias h)
  - RDN case: zod.hotlist_json_denormalized.case_number (blank when empty; **not** remote_order_id)
  - Claimed By: org name for agent_org_id / claimed_by_org_id
  - Repo Agent Name: **blank** (no per-row source)
- Soft deps: DEV-2660 / DEV-2659 / DEV-2737; UniqueLprCaseClaims out of scope.
- Existing tests: `LprRepossessionsTest.php`, `LprRepossessionsQueryTest.php`

## Plan must cover

1. **Listing query** that reuses claims/dedup CTE + joins + backyard predicate, returns one row per triple for the headline window/org — **without** replacing `uniqExactIf` in the chart statement. Do not poison monthly-series/window cache keys (counts-only contract).
2. **Exporter class** CSV columns + filename shape from AC.
3. **Export control** on the **expanded** LprRepossessions chart (prep: ChartWidget action gap — smallest Filament-faithful way; do not convert card to TableWidget; do not export other cards).
4. **Async** via existing exporter job/`{high}` — never sync-block `getData()`/`toStat()`.
5. **Parity tests** (row count == db_repos same filters) + exporter/unit coverage; update only tests that assert old copy/behavior as needed.
6. **Provision notes** — fleet ledger + real Docker CH (#18); Cloud CH RO only if needed for measure (prefer fleet twin).
7. Hard stops + soft collisions recorded.

## Required output structure for the draft

Markdown with:
- Summary (1 short paragraph)
- AC → implementation mapping
- File touch list (create/edit) with why
- Query/export design (grain, window, columns, cache safety)
- Chart action placement (expanded only)
- Tests
- Provision / QA notes (#18/#27/#25 parity)
- Hard stops / out of scope
- Open questions (empty unless real QG)

Do **not** implement code. Do **not** write `plan.json` / `plan.md`. Stdout only.

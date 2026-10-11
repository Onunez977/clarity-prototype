# Dual-plan draft (preflight #23) — DEV-2660

You are an independent Ultra-Plan co-pilot. Read-only tools only (Read, Grep, Glob). Do not mutate files, commit, push, or touch Herd/fleet env. Do not invent a fleet DSN. Cloud ClickHouse is READ-ONLY — do not write it and do not invent connection strings. Do not implement product PHP.

Ticket: https://insightlpr-team.atlassian.net/browse/DEV-2660
Repo: insight-lpr/platform (this worktree). Branch path **main→main** (First Glance comment **42078**, supersedes 42077). Monday cutover live.
Artifacts already present: `docs/investigations/DEV-2660/{prep-context,state,journal,precedents,ticket-status}.*`
Gate CLEAN @ HEAD `d68a331c4fb10867445741d7c2117acd01fdfcf8` (prep base `origin/main` `76d4f5fcfdb37a9cb3b7b667c9e34a0d2f4f3266`).

## Task

Produce an **independent** Implement plan for DEV-2660. Do not rubber-stamp any existing plan if present. Re-read:

- `app/Clickhouse/Queries/LprRepossessionsQuery.php` (recoveries leg ~200–215, `{floor}` at buildSql line 147, docblock A1 vs B)
- `database/schema/clickhouse-schema.sql` (`clarity.hotlist` first_*_at; `hotlist_incremental_mv` hsl_deduped minIf)
- `tests/Feature/Clickhouse/LprRepossessionsQueryTest.php` sentinel fixtures
- `tests/Unit/Clickhouse/BillingClaimDedupTest.php` status-log / floor pins
- `docs/investigations/DEV-2660/prep-context.json` locked_shape + measure_outline
- Relevant `okf/` pages — mark UNPROVEN gaps; do not invent wiki

Then write your own concise plan in markdown to stdout.

## Locked scope (do not reopen)

- Retire only the recoveries-leg `zod.public_hotlist_status_log` LEFT JOIN.
- Read `clarity.hotlist.first_repossessed_at` (MV `minIf(..., new_status = 3)`). Adopt `min()` knowingly (0.187% vs prior any/max).
- Dump name `first_declined_at` = status 4 — **not** the recoveries column. Not `first_closed_positive_at`.
- Keep: `zod.public_hotlist FINAL` (status = 3) as the (org, vin) match; un-normalised `h.vin`; −7 predicate deliberate; class does **not** extend `ClickhouseQuery`; no `hotlist_status_aggregate`; sentinel guards + fixture on the new join key; product PHP only.
- Do not collapse both joins into a single `clarity.hotlist` rewrite (architecture out of this key).
- Do not reopen the billing-leg change. Do not edit CohortClosedQuery / CohortDeclinedQuery. No okf/ edits in flight.

## Must decide / include

1. **`{floor}` keep or drop with a written reason** (ticket rule: remove if dead after the log scan is gone; else keep and say why). −7 on the `db_repos` predicate stays either way.
2. **Preflight #25 measure plan** (Cloud CH READ-ONLY, before any product swap): old-vs-new for Jul 2026 UTC platform-wide `db_repos` + AC-1 agreement %. Prior snapshots 9137 / 9149 are **not** the live baseline. Include `X-ClickHouse-Summary` (`read_rows`, `read_bytes`, `memory_usage`) expectations. Also measure candidate with/without `FINAL` on `clarity.hotlist` (wiki UNPROVEN for this column).
3. **Preflight #18**: Agent QA later needs real fleet Docker CH — note only; no host/port/DSN invent; provisioned=false.
4. **Preflight #24**: smallest change; no new abstractions.
5. Minimal Implement steps for the implementer (files, SQL shape, test pin updates, docblock AC-7 rewrite so variant B is viable because the transition timestamp exists).
6. Explicit out-of-scope / non-goals.

## Output

Markdown only to stdout. Prefer simple wins. Reject scope inflation. End with any disagreements vs `prep-context.json` locked_shape if you notice them.

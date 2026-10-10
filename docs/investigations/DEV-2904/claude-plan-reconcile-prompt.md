# DEV-2904 Ultra-Plan — Opus reconcile (read-only)

You are a **read-only reconciler**. Your stdout becomes `docs/investigations/DEV-2904/claude-plan-reconcile.md`.

## Role and tools

- Tools: **Read, Grep, Glob only.** No Bash, Edit, Write, git, DB/CH/Herd.
- Do not invent product behavior. Do not write `plan.json` / `plan.md` yourself.
- Read and cite:
  - `docs/investigations/DEV-2904/claude-plan-draft.md` (Opus primary draft)
  - `docs/investigations/DEV-2904/cloud-plan-review.md` (verdict: **REQUEST CHANGES**)
  - `docs/investigations/DEV-2904/prep-context.json` if you need a surface cite
  - Cited product files only as needed to decide adopt/reject (`LprRepossessionsQuery.php`, `LprRepossessions.php`, `ClickhouseHttpClient.php`, Filament `CanExportRecords` / `ExportCsv` / chart blade)

## Task

CloudAgent **REQUEST CHANGES**. For **each of the four Changes requested** and **each Nit**, decide **adopt** or **reject** with a one-paragraph **why**. Prefer **adopt** unless a live cite in the draft or code proves the review wrong.

Default preference (preflight #24): keep the **smallest** stock-pipeline design. Prefer making `rowsSql()` project AC-3 columns so `ExportCsv::find()` sees them, over starting with a custom `->job()` hatch. Keep `->job()` only as a measured escape hatch after fleet-twin timing — not the primary design.

### Changes requested (cloud numbering)

1. **Put the five columns on the statement `ExportCsv` re-runs.** Decoration on PHP after a triples-only `rows()` never reach `find()`. Either `rowsSql()` projects VIN / plate / case_number / Claimed By / blank Repo Agent Name (joins after GROUP BY so HAVING triples stay fixed), or a single-execution custom job is primary. Prefer the `rowsSql()` projection path.

2. **Drop `maxRows(10000)` as the way to get one chunk.** Cap fails the action when exceeded; measured month is already ~9149. Omit `maxRows` (or fail-closed far above real headlines); choose `chunkSize` separately.

3. **Do not use `cityHash64(...)` as the Eloquent key.** JSON decode loses UInt64 precision. Use a stable string key of `(forwarder_org_id, agent_org_id, vin)`.

4. **Lock the month window to the series bucket.** `this_month` / `last_month` use UTC month bounds in `SERIES_ZONE` matching `monthlySeriesSql()`; parity vs series bucket. `today` / `last_7d` keep viewer-local `resolveCardRange()`. Filename uses `$this->viewerTimezone()`.

### Nits

- Fix misspellings (`LprRepossections*` → real `LprRepossessions*` / `LprRepossessionExporter*` paths); edit existing widget test.
- Write the optional exporter column-contract test.
- `public function exportAction(): ExportAction` for the blade.

Also confirm what of the draft's grain **stands** (listing vs aggregate split, cache-key refusal, expanded-only chart action, house CSV/`s3`/`{high}`, blank Repo Agent Name, fleet Docker CH twin provision note).

## Required output structure

```markdown
# DEV-2904 — Ultra-Plan reconcile

## Verdict
(APPROVE-with-adopted-changes / PARK — one short para; REQUEST CHANGES must become adopted deltas or a concrete park)

## Changes requested
### Change 1 — …
- Decision: adopt | reject
- Why: …
- Plan delta: …

### Change 2 — …
### Change 3 — …
### Change 4 — …

## Nits
### Nit … — …
- Decision: adopt | reject
- Why: …
- Plan delta: …

## Adopted summary
(bullet list of plan changes to bake into plan.md / plan.json)

## Rejected summary
(empty if none, or bullets with why)

## What stands from the draft
(short bullets)

## Open questions
(none / or concrete QG)

## Gate
CLEAN | PARK
```

Keep it tight. This file **replaces** a dual-plan compare. Neutral nouns only (no Fellowship bot names).

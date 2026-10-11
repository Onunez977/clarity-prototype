# Regression strategy

Release QA proves **two** things: the new stuff works **and** existing features still work. This file
is how release-prep builds that — the blast-radius sweep (Step 2), how the tiers split across the two
QA passes, and how to make the agents test *with understanding* (the what/why).

## Two hard constraints (verified against the downstream skills)

1. **agent-qa's browser subagents receive only the item prose** (where-to-click + what-to-look-for)
   plus the plan's "What's in the QA database" line. They do **not** get the release brief, the
   "Ticket passing requirements," or any `covers: AC` metadata. → **Bake the what/why into each
   item's text** or the agents never see it.
2. **agent-qa runs ≤3 browser lanes and folds extras greedily** (smallest first). → Regression must
   be **woven into the ≤3 surface lanes**, never added as a 4th lane. The full sweep lives in
   human-qa, which has no lane cap.

Both are honored **without forking the plugin**: context rides in plan fields that already render —
the item prose for agent-qa, `action`/`look for` for human-qa.

## The three tiers

- **T1 — New / changed.** The per-ticket feature lanes ([`plan-authoring.md`](plan-authoring.md)).
- **T2 — Blast radius.** Untouched surfaces sharing code with the diff (computed below).
- **T3 — Core critical paths.** The curated [`core-critical-paths.md`](core-critical-paths.md) battery,
  run every release. If the range touched Scout surfaces, also fold the plugin's
  `scout-critical-paths.md`.

## Intensity (Step 0 arg; default `standard`)

| | T1 new | T2 blast-radius | T3 core battery |
|---|---|---|---|
| `light` | full | — (skip) | Smoke lane only |
| `standard` | full | yes | thin smoke woven into agent lanes / **full** in human sweep |
| `thorough` | full | yes + one hop further | **full** battery in BOTH agent + human |

## T2 — blast-radius sweep (Step 2)

Goal: surfaces a release could break **without touching them directly**, via shared code. For the
changed files in `$TAG..HEAD` (through `rel_filter`), pull the **shared symbols** and find their
*untouched* referrers:

```bash
CHANGED=$(git -C "$TARGET" diff --name-only "$TAG"..HEAD | rel_filter)

# Shared symbols worth tracing (high fan-in): models, services, ClickHouse query classes,
# Filament base/traits/concerns, Blade components/layouts. For each changed one, grep referrers.
for f in $CHANGED; do
  case "$f" in
    app/Models/*|app/Services/*|app/Clickhouse/*|app/Filament/*/Concerns/*|app/View/Components/*|resources/views/components/*|resources/views/layouts/*)
      sym=$(basename "$f" .php)
      # referrers NOT in the diff = blast radius
      git -C "$TARGET" grep -l -E "\\b${sym}\\b" -- 'app/**/*.php' 'resources/views/**/*.blade.php' \
        | grep -vxF "$f" ;;
  esac
done | sort -u
```

Map each referrer to its **user-facing surface** (the Filament resource/page/widget that renders it)
and keep the surfaces that aren't already a T1 feature lane. Those are the T2 items. Bound it: trace
one hop (don't recurse the whole graph); cap to the top surfaces by referrer count; `log()` what was
dropped. Skipped entirely under `light`. A changed model with a hundred referrers → pick the handful
of distinct user-facing surfaces, not a hundred items.

Each T2 item is framed **"should be UNCHANGED"** and names *why it's at risk*:
> shares `HotlistResource` table config with DEV-1948 (which changed hit-alert filtering) — confirm
> the Hotlists index still filters/sorts/paginates as before.

## Authoring — where each tier lands

### Agent plan (≤3 lanes, woven)
- T1 items as before.
- **T2** items go into the **matching surface lane** (a Hotlists blast-radius check rides in the
  Hotlists `### F` lane), so no 4th lane is created.
- **T3** = a **thin smoke** subset (`standard`) or the **full** battery (`thorough`), also woven by
  surface affinity. Under `light`, only `core-critical-paths.md`'s Smoke lane.
- **Bake the what/why into every item's prose** — the only context the subagents get:
  ```
  - [ ] A4. [T2 · shares Hotlist model w/ DEV-1948] On Hotlists index, confirm filter + sort + CSV
        export still work unchanged — look for: rows filter live, CSV downloads, no console error.
  ```
  Prefix tier + cause in `[ ]`, then the action, then `look for:`.

### Human plan — `### Regression sweep` (`rg-<n>`)
A dedicated section after Happy path / Edge cases, carrying the **full** T2 + T3 (per intensity).
human-qa renders any section verbatim, so this Just Works with persistent checkboxes:
```markdown
### Regression sweep

_Existing features that must still work after this release. Each should be UNCHANGED unless noted._

- [ ] rg-1. **Hotlists index** — link: <url> · should be UNCHANGED · look for: filter/sort/CSV export
      behave as before · why: DEV-1948 changed shared hit-alert filtering. (covers-regression: T2)
- [ ] rg-2. **Dashboard** — link: <url> · should be UNCHANGED · look for: stat widgets + hotlist
      counts render, no 5xx. (T3 core)
```
Put the "what changed / why" in the `action`/`look for` prose (the fields that render — no template
fork). Use stable `rg-<n>` ids so checkbox state survives re-prep.

## Reachability — gated features must be testable

A T1/T2/T3 item behind a Pennant flag / Pro tier is useless if the QA persona can't reach it (agent
lands on an empty screen and "passes"). The release **QaSeeder must enable the tiers/flags the
battery and the release's features need** — put the QA pro-org on the right tier (write
`platform_repo_pro` / tier to its `org_meta`, per the platform's `Org::isProEnabled()` / `ProTierEnum`
model) and flip any experimental `*_ENABLED` config the items require. Mark Pro-gated battery rows so
this is explicit. (This is the QA-reachability counterpart to the changelog's gated-feature
*exclusion* — gated features are still QA'd, just not announced.)

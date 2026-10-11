# DEV-2138 — Plan

**Route:** bug · **Base:** main · **Complexity:** simple · **Blast radius:** none

Single subtask (frontend visibility removal, no server change). Resolved scope: **hide Repos entirely** (Q1) and **include the ProfilePanel sibling** (Q2).

## t1 — Remove the Repos stat tile everywhere

### `resources/js/clarity-scout/components/MapStatsBar.vue`
- Remove the conditionally-spread **Repos** block from the `stats` computed array (the `...(!(isCompact.value && mapStore.liveMode) ? [{ label: 'Repos', ... }] : [])` at ~191-197). Replace with a one-line breadcrumb comment: pulled pending the direct/staging split (DEV-2138).
- Remove the now-dead `const repos = useCountUp(() => source.value?.repos_affected);` (~130).
- Remove the now-dead `const { isCompact } = useViewport();` (~99) and the `useViewport` import (only user was the Repos guard).

### `resources/js/clarity-scout/components/ProfilePanel.vue`
- Remove the 4th "This Week" grid cell (**Repos**, ~67-70).
- Change the grid container `grid grid-cols-2 gap-4` → `grid grid-cols-3 gap-4` (~54) so the 3 remaining stats (Scans / Direct Hits / Staging Hits) fill one even row — **no empty cell** (AC #2).

### Build
- `npm run build`, commit the regenerated `public/build` assets.
- Revert boost-doc regeneration side effects (CLAUDE.md / AGENTS.md / copilot / junie / boost.json) before committing.

## Verification (no JS test runner)
Manual/visual via QA pipeline: Repos gone from Org Map stat bar (desktop+mobile, Today+historical) with no gap/orphaned divider; profile This Week is an even 3-up row; no console/lint/build errors from removed refs.

## Excluded
- HelpPanel 'Repossessions' glossary (static copy).
- Server-side `repos_affected` computation (metric split deferred by ticket).

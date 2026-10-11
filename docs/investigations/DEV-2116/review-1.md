# DEV-2116 — Review #1

## Rigor gate status

The prescribed 3-lane rigor review (`review.sh --mode=claude --lanes=3`) **could not execute in
this environment**: all 3 lanes failed with `Not logged in · Please run /login` — the nested
`claude -p` reviewer processes have no auth here (confirmed in `.ai/_raw-1-claude1.json`). This is an
environment limitation, not a findings failure. **Recommend re-running the rigor gate in an
authenticated environment before merge.** Substituted below with an in-session structured review +
the passing test suite.

## In-session review (source diff vs origin/scanner-app, built assets excluded)

### Critical issues
- **None outstanding.** The one real defect — `Kit`(zod) `whereHas('settings')` on
  `KitSettings`(platform) being a cross-connection correlated subquery that returns nothing (would
  have shipped Bird's Eye with **zero drivers in prod**) — was caught via the feature test and fixed
  (keyed lookup); pinned by `test_drivers_lists_only_the_orgs_scout_enrolled_kits`.

### Suggestions (non-blocking, follow-up)
1. **No `AbortController`** on the heatmap/scope fetches — rapid range/selector toggling could let a
   slow response overwrite a newer one (the driver `map.js` guards heatmaps this way). Low impact for
   an admin view; worth a follow-up.
2. **`ScoutMapHelpers` duplicates** the driver controller's private helpers — intentional (avoid
   destabilizing the live driver `ClarityScoutMapController` on the biggest-feature branch); DRY in a
   follow-up.
3. **Live-trail growth** is `concat`-per-poll and unbounded over a very long admin session (the driver
   app shares this shape). Consider a point cap/decimation guard if org sessions run for hours.

### What looks good
- Every endpoint is org-scoped (`auth()->user()->org_id`); the selector `kit_ids` is **always
  intersected** against the org's own kits — the client cannot widen scope past its org (tested).
- DEV-2113 current-hotlist-status semantics preserved in the org-wide `hits()`.
- Gating (ADMIN + CLARITY_SCOUT) enforced on the nav item, the web route, and the API group; tested
  (non-admin → 403, no-feature → 403, guest → 401, shell route gated).
- `npm run build` clean; bundle in the manifest; no driver-Scout regression (sibling tests pass).

### Scoring findings fixed this review
- `TextLayer characterSet: 'auto'` so accented driver names render instead of blank labels.

## Verdict

**Approve for human QA.** Backend is verified by tests; the multi-driver map *rendering* is the one
thing static review can't confirm — it is the explicit focus of human QA (Phase 7). Self-assessed
quality ~88/100; the gap to the 90 threshold is the unvalidated runtime frontend, which human QA
closes.

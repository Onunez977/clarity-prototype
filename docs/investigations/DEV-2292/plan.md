# DEV-2292 — Plan

**Route:** bug · **Base:** main · **Scope:** single file, frontend-only.

## Problem
On the Scout Org Map (Bird's Eye / admin), when the org is active today but no kit is
reporting a live GPS position, `resources/js/clarity-scout/App.vue` renders a centered
notice card that has no close control. A customer reported it as a stuck modal blocking the
map. (A sibling non-blocking bottom pill carries the same message.)

## Design (resolved with Dalton, 2026-08-03)
- **Keep** the centered card; **add a ✕** close button (Toast convention).
- One dismiss hides **both** the centered card and the bottom pill.
- Dismissal is **session-scoped**: in-memory ref, no persistence, no re-arm on state
  change. A page reload / new SPA session clears it.

## Subtask DAG
- **t1** (simple, no blast radius): edit `resources/js/clarity-scout/App.vue`.
  1. `const noticeDismissed = ref(false)` in `<script setup>`.
  2. Both notice `v-if`s → `noKitsReporting && !noticeDismissed` (centered card ~L37, bottom pill ~L81).
  3. ✕ button in the centered card: `aria-label="Dismiss"`, Toast X-SVG, positioned top-right,
     `pointer-events-auto` on the button only (container stays `pointer-events-none` so the map
     is draggable around it), `@click="noticeDismissed = true"`.
  4. `npm run build`; commit regenerated `public/build`.

## AC mapping
- **AC1** dismiss any time → ✕ button.
- **AC2** map interactive after dismissal → both surfaces hide; centered card gone; button-only pointer-events.
- **AC3** auto-hide when kits report → unchanged reactive `noKitsReporting` (L330-336).
- **AC4** no reappear until next session / meaningful change → session-scoped in-memory ref (Dalton chose the "next session" branch).

## Tests
No JS test runner in this repo; App.vue is Vue-only with no PHP logic touched. Verification is
agent + human QA of the Org Map admin view. Full PHP suite runs at the Phase 4 boundary (unaffected).

## Gates
- Sizing: 1 file / 1 concern — no split.
- Blast radius: none.
- CH-at-scale perf gate: N/A (no ClickHouse surface).

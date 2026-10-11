# DEV-2599 plan

**Phase:** planned. Input: [`prep-context.json`](prep-context.json) (gate CLEAN, confidence 88).

Base: `main` @ `845f656c` (includes DEV-2594). No stacked Scout branch.

## What ships

Clicking the plate or vehicle image on Hit Details opens a larger view of the same capture URL. Closing it leaves Hit Details open. The date-range pill and the map stay put.

Admin org map and the driver view both use `MapShell`. One wiring covers both.

## t1 — Enlarge control (moderate)

Depends on nothing.

Files:

- `resources/js/clarity-scout/components/ScanImageLightbox.vue` (new)
- `resources/js/clarity-scout/components/MapShell.vue`
- `public/build` and `public/clarity-scout-sw.js` only as `npm run build` output

Behavior:

- Buttons around the existing images at `MapShell.vue` (plate `object-contain`, vehicle `object-cover`, both `h-48`).
- Lightbox is teleported to `body` at `z-[60]`, above the DEV-2594 sheet wrapper (`z-[45]`).
- Image uses `object-contain` and the src already on the hit (`scan_img` → `https://images.insightlpr.io/{guid}-c.jpg` or `-p.jpg`). That is the full capture file this app serves. No zoom library.
- Close (button, backdrop, Escape) clears local state only. It does not call `clearSelectedHit`, `focusHit`, or `dashboard.setRange`.
- If the open hit changes, close the lightbox.
- Do not re-expand the Live Hits list. DEV-2594 collapses it on purpose when a hit is selected.

Blast radius:

- **shared-map-shell** — `MapShell` is the persistent map host for both surfaces.
- **hit-details-also-used-by-map-markers** — logo-marker taps render these same images, so they gain enlarge too.
- **committed-vite-bundle** — rebuild Scout assets; do not edit hashes by hand.

## t2 — Playwright (simple)

Depends on t1.

Files:

- `tests/e2e/dev-2599-enlarge-scan-image.spec.ts`
- `tests/e2e/helpers/dev-2599.ts`

Admin `/scout/org-map` only, reusing the DEV-2597 login and route stubs. Flow: choose 7 Days, open the mocked live hit, enlarge the vehicle image, close, assert the plate is still on Hit Details and 7 Days is still pressed.

No new driver session helper. The driver view is the same component with no `adminMode` fork on these images. Driver click-through stays on the QA stub in `state.json`.

## Left alone

Live Hits list rows, `map.js`, `dashboard.js`, Trip/Day sheets, `scan_img()`, both recent-hits controllers.

## Later CLI

This branch stops at phase `planned`. Implementation, review, and QA are the next CLI step (`state.json` → `cli_provision`).

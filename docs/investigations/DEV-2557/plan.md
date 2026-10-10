# DEV-2557 — Implementation Plan

Hotfix · Clarity (platform) · branch `claude/dev-2557-e229d9` · base `deploy`

Two unrelated defects on the Zone Manager (`/app/manage-zones`), scoped into one ticket by the
operator. This plan is derived from `prep-context.json` (gate CLEAN, confidence 86) plus a
line-level read of the files it named. Everything below was verified against the actual source,
not inferred.

---

## Approach per defect

### Defect 1 — chips are illegible (AC1, AC2, AC3, AC9)

The panel's `primary` ramp is degenerate: `app/Support/Filament/PlatformPanelProvider.php:37-53`
sets shade 50 to `#FBE9F5` (leftover fuchsia) and shades **100 through 950 all to `#FF6666`**.
Any component that pairs two primary shades for background and foreground therefore paints the
same colour twice.

The operator rejected fixing the token (decision #2): buttons and links must not move in either
panel. So the remedy is a CSS override **scoped to chips only**, in
`resources/css/filament/app/theme.css`.

Two facts make that override clean:

1. **The theme file does not redefine `primary`.** Confirmed by reading it end to end — it is a
   pure override file (sidebar, topbar, stats cards, filter sidebar, Google Maps info windows,
   methodology page). Nothing there touches the colour token.
2. **Unlayered beats layered.** `vendor/filament/support/resources/css/index.css` imports
   `components/badge.css` into `layer(components)` and `utilities.css` into `layer(utilities)`.
   Every rule this repo writes in `theme.css` sits after the `@import` and is **unlayered**, and
   unlayered declarations win over every cascade layer regardless of specificity. So overriding
   `--text` / `--dark-text` (which `.fi-text-color-600` / `dark:fi-text-color-200` set on the badge
   element, utilities layer) and `background-color` (which `.fi-badge.fi-color`'s `bg-color-50` /
   `dark:bg-color-400/10` set, components layer) needs **no `!important` and no specificity
   games**. The rules already in this file exploit the same mechanism.

Four custom properties are declared once on `:root` and consumed by both chip families. Values are
the shades `Color::hex('#FF6666')` would generate — real, differentiated shades — assigned to
tokens rather than pasted inline at each call site, which is what AC3 asks for.

### Defect 2 — clicking a premade ZIP boundary deletes it (AC4, AC5, AC6, AC7)

ZIP-derived overlays are built by `renderEditableZip()` (`zone-manager.js:446-459`) using
`zipStyle()` (`:550-558`), which carries **no `editable` flag** — so they render as plain,
non-editable polygons. Their only listener calls `removeZip()` (`:658-664`), which does
`overlay.setMap(null)`. That listener is attached at **two** sites: `:434` in `hydrateZips` and
`:640` in `addZip`. Both must change. (Note: `renderEditableZip` is a misleading name — it renders
nothing editable.)

Drawn polygons take the other path — `editablePolygonStyle()` (`:538-548`, `editable: true`) plus
`attachEditablePolygonHandlers()` (`:560-567`) — and are never wired to delete-on-click.

The fix mirrors the drawn-polygon path onto ZIP overlays, with DETACH (decision #1) as the
persistence model: on first real vertex edit, the shape moves into `polygonOverlays` and the ZIP
leaves `zipCodes`. `syncEditingState()` (`:689-697`) then serialises it into
`definition.polygons`, and because the ZIP is gone from `definition.zip_codes`,
`Zone::rebuildGeometryFromDefinition()` (`app/Models/Zone.php:251-296`) stops re-deriving it from
`tiger_data.zcta5_all`. The edited shape becomes authoritative and survives reload. **Zero schema
change, zero server change** — the existing `{polygons, zip_codes}` save path is reused verbatim.

---

## DAG

```
t1 (CSS tokens + blade chips)  ──┐
                                 ├──▶ t2 (zone-manager.js detach + blade notice) ──▶ t4 (npm run build)
t3 (PHPUnit persistence tests) ──┘                                                      ▲
                                                                                        │
                                                          t1 ────────────────────────────┘
```

| Node | Title | Complexity | Parallel | depends_on |
| --- | --- | --- | --- | --- |
| **t1** | Chip contrast — theme.css tokens + ZIP chip + drawing banner | complex | yes | — |
| **t2** | ZIP overlay — click selects, first vertex edit detaches | complex | no | t1 |
| **t3** | Pin the DETACH definition shape at the Livewire boundary | simple | yes | — |
| **t4** | Rebuild + commit `public/build` | complex | no | t1, t2 |

**t1 ∥ t3** is real parallelism — disjoint files (`theme.css` + blade vs the test file).
**t2 is serialized behind t1** for one honest reason: both edit
`resources/views/filament/pages/manage-zones.blade.php` (t1 the chip and banner classes, t2 the
detach notice). The partition fails there, so the DAG serializes rather than pretending.

**What each node pins**

- **t1** — the only node that can satisfy AC1/AC2/AC9. It carries a measurement gate: the
  implementer records the computed WCAG ratio for both shipped pairs in the PR body.
- **t2** — the only node that touches map interaction. It has no PHPUnit reachability; its
  `test_filter` is a smoke guard that the page still renders.
- **t3** — the server half of AC6, plus a regression guard that t1's styling work left the
  `definition` round-trip untouched.
- **t4** — the difference between a real fix and a production no-op.

---

## AC → node mapping

| AC | Node(s) | How it is satisfied |
| --- | --- | --- |
| AC1 ZIP chip light mode ≥ 4.5:1 | t1, t4 | `.clarity-chip` = `#8C3132` on `#FFE7E4` (6.82:1). Today `#FF6666` on `#FF6666` = **1.00:1**. |
| AC2 ZIP chip dark mode ≥ 4.5:1 | t1, t4 | `#FFCFCB` on `primary-500 @ 18%` over gray-900. Today it passes only by accident of the flat ramp. |
| AC3 chip styling uses theme tokens | t1 | Four custom properties declared once on `:root` in `theme.css`, consumed by both chip surfaces — no inline hexes at any call site. |
| AC4 click selects and shows edit handles | t2, t4 | `google.maps.Polygon` `click` → `selectZip()` → `setOptions({editable: true})`, which is what makes Google render vertex + midpoint handles. |
| AC5 selected ZIP editable like a drawn polygon | t2, t4 | Same `editablePolygonStyle()` the drawn path uses, plus `attachEditablePolygonHandlers()` after conversion. |
| AC6 edits persist and survive reload | t2, t3, t4 | Client: detach pushes into `polygonOverlays`, `syncEditingState()` writes `definition.polygons`. Server: the ZIP is gone from `zip_codes`, so nothing re-derives it. t3 pins both halves in PHPUnit. |
| AC7 removal only via an explicit action | t2, t4 | The plain map click no longer reaches `removeZip()`. `removeZip` stays wired to the chip `×` (blade :157) and `handleZipBackspace` (:653); `clearEditing()` keeps its confirm. |
| AC8 verified both modes, existing + new zone | agent QA + human QA | No JS test runner in this repo. See the QA note below. |
| AC9 Assigned Kit Users chip + other consumers | t1, t4 | The Select multi-value badge selector reaches every `->multiple()` Select chip in **both** panels — `PlatformPanelProvider` is a trait used by `App.php` (`/app`) **and** `Client.php` (`/client`), and there is exactly one `viteTheme()` call, so one stylesheet serves both. That is how `ClientTable.php:299` in the client portal is covered. |

**AC9 is the one that is not fixed for free.** Filament hardcodes
`fi-badge fi-size-md fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-200` in
`vendor/filament/support/resources/js/utilities/select.js:751` and in the compiled
`vendor/filament/forms/dist/components/select.js` (verified present in both). It bypasses
`BadgeComponent::getColorMap()` entirely, so no `->color()` API reaches it. CSS is the only lever.

---

## Judgement calls you asked for

### 1. The exact CSS selector

```css
.fi-select-input-value-badges-ctn .fi-badge.fi-color-primary,
.fi-fo-tags-input-tags-ctn      .fi-badge.fi-color-primary { … }
```

Verified against the real rendered DOM, not guessed:

- `select.js:748-782` builds `<span class="fi-badge fi-size-md fi-color fi-color-primary
  fi-text-color-600 dark:fi-text-color-200" data-value="…">` and appends it into a
  `<div class="fi-select-input-value-badges-ctn">` created at `select.js:824`. That container class
  is produced **only** by `addBadgesForSelectedOptions` and appears in the shipped
  `vendor/filament/forms/dist/components/select.js` bundle.
- `fi-fo-tags-input-tags-ctn` is produced only by
  `vendor/filament/forms/resources/views/components/tags-input.blade.php:101`.

**Scoping, and why it does not bleed.** Both selectors are anchored to a container that exists
only around form-field *value chips*. Table column badges, infolist and status badges, and any
standalone `<x-filament::badge>` are untouched — no regression to those surfaces.

**Why TagsInput is included even though prep implied it was fine.** `questions[4]` says
`getColorMap()` "governs the Blade badge/TagsInput" and "yields a passing 5.67:1". I read
`vendor/filament/support/src/View/Components/BadgeComponent.php:16-56`: it walks the shades testing
`Color::isTextContrastRatioAccessible($color[50], $color[$shade])`. On the degenerate ramp every
shade 100-950 is `#FF6666` against `$color[50] = #FBE9F5` — **every check fails**, so it falls
through to `$text ??= 900` and `$darkText ??= 200`, both `#FF6666`, i.e. ~2.46:1. The 5.67:1 figure
only holds on a *fixed* ramp. Prep's `exhaustiveness` sweep was right to list the three
`AlertSettingsForm` TagsInputs as buggy; `questions[4]`'s parenthetical reads as if they were not.
So the selector covers both.

**Why not a bare `.fi-badge.fi-color-primary`.** It is one line shorter and would sweep up the
table/status badges too. I rejected it: it would recolour every primary badge across both panels —
including the customer-facing client portal — in a hotfix, unreviewed by Design, for badges nobody
reported. The operator's whole reason for rejecting the token fix was to keep unrequested visual
change out of this hotfix; widening the badge blast radius contradicts that. If QA decides it wants
the table badges too, deleting the two container prefixes is a one-line change.

### 2. Where the DETACH conversion fires

Two deliberately separate `google.maps` event families:

| Intent | Event | Fires on |
| --- | --- | --- |
| **Select** (no conversion) | `polygon.addListener('click', …)` — `google.maps.Polygon` `click` | a plain click anywhere on the fill or stroke |
| **Convert** | `polygon.getPath().addListener('set_at' \| 'insert_at' \| 'remove_at', …)` on the `MVCArray<LatLng>` | vertex dragged to a new position; midpoint handle dragged into a new vertex; vertex right-click-deleted |

A click **never writes to the MVCArray**, so selection alone cannot fire any of the three path
events. That is what keeps "click selects" and "drag detaches" cleanly apart — there is no
heuristic, no timer, no drag-distance threshold.

These are the same three events `attachEditablePolygonHandlers` (`:560-567`) already uses for drawn
polygons, so the conversion trigger uses proven vocabulary rather than a new mechanism.

Two details that matter:

- **Idempotency.** `set_at` fires repeatedly during a single drag. `detachZip(zip)` opens with
  `const overlays = this.zipOverlays[zip]; if (!overlays) return;` — after the first fire the ZIP is
  gone from `zipOverlays` and every subsequent fire is a cheap no-op. The path listeners are also
  captured in `this.zipListeners[zip]` and removed with `google.maps.event.removeListener` inside
  `detachZip`, so nothing lingers.
- **All rings, together.** `renderEditableZip` creates **one overlay per ring**, so a multi-polygon
  ZIP is several overlays. Detach promotes *all* of them; promoting only the edited ring would
  silently drop the rest of the ZIP's area.

`dragend` is deliberately unused — these overlays are `draggable: false`.

### 3. Should the ZIP chip keep `primary-*` Tailwind classes?

**No.** AC3 says chip styling uses theme tokens, and under the chosen route `primary-*` resolves to
the flat ramp — keeping those classes would mean keeping the bug. The chip (`:152`), its `×` button
(`:157`), and the drawing banner (`:108`) move to `clarity-chip` / `clarity-chip-btn`, which read
the new custom properties. Layout classes (`inline-flex`, `rounded-md`, `px-2`, `text-xs`, …) stay
as Tailwind.

Two carve-outs:

- The banner's `border-primary-400 dark:border-primary-500` **stays**. A border is not a
  text-contrast surface, and keeping it preserves the brand edge.
- The banner at `:108` is included even though no AC names it. It is the same defect class
  (`bg-primary-100` + `text-primary-900` = `#FF6666` on `#FF6666` = **1.00:1**, completely
  illegible) on the very page this ticket fixes, four lines from the chip. Shipping a
  contrast hotfix for Manage Zones that leaves an unreadable banner on Manage Zones is not a
  defensible hotfix.

### 4. `app/Filament/Forms/Components/ZoneMapDisplay.php` — verdict

**Read-only display. Out of scope.** Checked, not guessed:

- The `Field` sets `->dehydrated(false)` (`:24`) — it never writes state back.
- `resources/js/components/zone-map-display.js` is a separate 324-line entry point whose **only**
  listeners are `mouseover`, `mousemove`, `mouseout` on a `google.maps.Data` layer (`:105-107`).
  No `click` handler, no `editable: true`, no `DrawingManager`, no `$wire` write, no `setMap(null)`.
- Its chips are `<a>` links styled from per-zone `Zone::PRESET_COLORS` hexes
  (`zone-map-display.blade.php:26-46`), never the `primary` token — which independently confirms
  prep's "SAFE" classification of that file.

Neither defect is duplicated there. The ZIP-shape interaction model exists only in
`zone-manager.js`.

### 5. Sizing gate — split into two tickets?

**No. Ship it as one.** Recommendation, not a survey.

The two concerns are genuinely unrelated in mechanism, which is the usual argument for splitting.
But: it is a single reported hotfix; the operator scoped ACs 1-9 into one ticket ten minutes ago
and then answered all three blocking questions specifically to make the *whole* of it shippable;
both defects live on the same page and will be QA'd in the same browser session, in the same two
colour modes, on the same seeded zone; and the two halves share a mandatory `npm run build` that
would otherwise have to be done and reviewed twice against a git-tracked `public/build`. Splitting
buys cleaner history and costs a second full QA cycle and a second asset rebuild on a hotfix
branch — a bad trade.

The plan already keeps the concerns separable inside the ticket: t1 and t3 are independent nodes,
and t2 is the only node touching the map. If review wants to drop half, t2 can be reverted without
touching t1.

---

## Blast radius

**`blast_radius: []` holds — verified, not assumed.** The four nodes touch
`resources/css/filament/app/theme.css`, `resources/views/filament/pages/manage-zones.blade.php`,
`resources/js/components/zone-manager.js`, `tests/Filament/Pages/ManageZonesTest.php`, and
`public/build/**`. None matches any sensitive glob: no `database/migrations/**`, no billing job or
resource, no Shield/Policy/auth config, no `Clickhouse*Purge|Cleanup`, no `PruneScans`. No
ClickHouse table and no materialized view is read or written. The only database surfaces involved
(`platform.zones`, `platform.zone_user`, `tiger_data.zcta5_all` read-only) are reached exclusively
through code paths this ticket does not modify.

---

## Corrections to `prep-context.json`

Found by opening the files. Prep's *conclusions* hold; these are stale or misleading rationales
that would mislead an implementer.

1. **`acceptance_criteria` AC1 and AC2 rationales are stale.** Both read "Token fix alone satisfies
   it: bg-primary-100 `#FFE7E4` / text-primary-800 `#8C3132` = 6.82:1." The token fix was
   **rejected** by `questions[1]`. Under the chosen chips-only route the ramp stays flat, so those
   pairings never exist at runtime — the CSS must supply the values itself. The 6.82:1 *target* is
   still right; the *mechanism* named is not the one being built.
2. **`qa_hint.plan_sketch` contradicts the ACs.** It records AC5/AC6 as "N/A this ticket — deferred
   pending the Product decision". `questions[0]` answered DETACH and `acceptance_criteria` marks
   both "UNBLOCKED / implement". QA must cover AC5/AC6; the sketch must not be followed as written.
3. **`questions[4]`'s parenthetical understates the damage.** It implies the
   Blade-badge/TagsInput path "yields a passing 5.67:1". On the degenerate ramp
   `BadgeComponent::getColorMap()` falls through to shade 900 / 200 — both `#FF6666`, ~2.46:1. Those
   chips are broken too, which is why the selector covers `fi-fo-tags-input-tags-ctn`.
4. **The four 'Pro' badges do not shift.** `scope.out_of_scope` says they "shift incidentally with
   the token fix and must be spot-checked for regression". Under the CSS-only route nothing about
   them changes — they are hand-rolled Tailwind, not `.fi-badge`. The spot-check item is moot.
5. **`renderEditableZip` renders nothing editable.** `zipStyle()` has no `editable` flag. The name
   is a trap; prep repeats it without comment.
6. **Both `removeZip` click sites must change.** Prep's prose describes the fix in the singular; its
   `ref` list does name `:434,640`. There are two identical listener attachments (`hydrateZips` and
   `addZip`) and fixing one leaves the bug live for freshly-added ZIPs.

Every other line-number reference in prep (`zone-manager.js:434,640,658-664,550-558,538-567,689-697`;
`manage-zones.blade.php:108,152,157`; `PlatformPanelProvider.php:37-53`;
`select.js:751`; `badge.css:44-50`) checked out exactly.

---

## Risks / what could go wrong

- **Shipping as a no-op.** `public/build` is git-tracked (15 files) and both changed sources are
  Vite entry points (`vite.config.js` input array). Without t4 the browser keeps the old hashed
  bundles and nothing changes in production. Mitigated by making t4 a node with an explicit
  grep-the-bundle verification.
- **`npm run build` regenerates the Boost docs.** It rewrites `CLAUDE.md` / `AGENTS.md` / the junie
  guidelines and drops a `boost.json`. Committing those is out-of-scope drift, and a re-inlined
  `<laravel-boost-guidelines>` block fails `php scripts/okf-lint.php`. t4 must revert them before
  staging, and its diff must be `public/build/**` only.
- **The chip hexes are frozen.** Because the token is left degenerate by decision, the chip values
  are literal shades in `theme.css`. If Design later fixes the ramp, the chips will *not* follow
  and will need a second edit. That is inherent to the chosen route. The alternative —
  `oklch(from var(--primary-500) …)` relative colour syntax, which would auto-follow — was rejected
  as too clever for a hotfix and harder to measure. Worth revisiting on the token follow-up ticket.
- **Vendor class names are the fix's hinge.** `fi-select-input-value-badges-ctn` and
  `fi-fo-tags-input-tags-ctn` are Filament internals. A Filament patch that renames either silently
  reverts the AC9 half with no error. Version is pinned in `composer.lock`, so this is a future-
  upgrade hazard, not a ship hazard — but note it in the PR.
- **`set_at` storm during a drag.** The detach guard must be the *first* statement in `detachZip`.
  If conversion runs twice the overlay is pushed into `polygonOverlays` twice and the zone saves a
  duplicated polygon. This is the single most likely implementation bug in t2.
- **The ZIP chip vanishing without explanation.** Inherent to DETACH. Mitigated by the
  `detachNotice` line in t2, and it must be called out explicitly in the human QA guide so the
  tester does not report it as a new bug.
- **Multi-ring ZIPs.** Promoting only the edited ring would silently drop the rest of the ZIP's
  area — a data-loss bug dressed as a UI change. t2 promotes all overlays for the ZIP together.
- **Partially mitigated coverage.** There is no JS test runner, so AC4/AC5/AC7 have **no**
  automated coverage at all; only the `definition` shape (t3) is pinned. AC8 is therefore
  load-bearing, not ceremonial: agent QA must exercise click-does-not-delete and drag-then-save,
  and human QA must confirm both colour modes on an existing **and** a new zone.
- **QA seeding.** The scenario needs one org, one ADMIN (ZonePolicy requires ADMIN + same-org), one
  zone carrying **both** a drawn polygon and a ZIP whose boundary exists in the local
  `tiger_data.zcta5_all` fixture, and at least one kit-assigned user so the "Assigned Kit Users"
  chip actually renders — without that last one, AC9 cannot be observed at all.

---

## Driver amendments (session B, 2026-09-15)

Two corrections found by verifying the plan's concrete API claims against source. Both are
binding on the implementer.

### A. `deselectZip()` must pass `editable: false` explicitly — `zipStyle()` cannot turn it off

`zipStyle()` (`zone-manager.js:550-558`) returns **no `editable` key at all**:

```js
zipStyle: function () {
    return { strokeColor: '#059669', strokeWeight: 2, fillColor: '#059669',
             fillOpacity: 0.2, clickable: true };
},
```

`google.maps.Polygon.setOptions()` applies only the keys present in the object it is given. So
`o.setOptions(this.zipStyle())` after a `selectZip()` leaves `editable: true` in place and the
vertex handles stay on screen — the overlay looks deselected but is still editable, and a stray
drag still fires `set_at` and detaches a ZIP the user believed they had deselected.

`deselectZip()` must therefore be:

```js
o.setOptions({ ...this.zipStyle(), editable: false });
```

Note the same asymmetry does not exist on the drawn-polygon path: `editablePolygonStyle(color)`
(`:538-548`) *does* carry `editable: true` explicitly, which is why `selectZip()` spreading it
works as the plan describes. Confirmed: `editablePolygonStyle` takes a colour argument, so
`this.editablePolygonStyle('#059669')` in `selectZip()` is a valid call.

### B. The `×` delete-icon override must carry its `:hover` / `:focus-visible` variants

`vendor/filament/support/resources/css/components/badge.css:68-77` gives the delete button icon
interaction states:

```css
&.fi-color .fi-badge-delete-btn > .fi-icon {
    @apply hover:text-color-700/75 focus-visible:text-color-700/75
           dark:hover:text-color-300/75 dark:focus-visible:text-color-300/75;
}
```

Those live in the `components` layer. The plan's flat, **unlayered** `color:` override on
`.fi-badge-delete-btn > .fi-icon` beats them regardless of specificity — which is the whole point
of the mechanism, but it also means the `×` silently loses its hover and focus feedback. That is a
small accessibility regression shipped inside an accessibility fix.

Add matching variants alongside the base rule, e.g.:

```css
.fi-select-input-value-badges-ctn .fi-badge.fi-color-primary .fi-badge-delete-btn > .fi-icon:hover,
.fi-select-input-value-badges-ctn .fi-badge.fi-color-primary .fi-badge-delete-btn > .fi-icon:focus-visible,
.fi-fo-tags-input-tags-ctn .fi-badge.fi-color-primary .fi-badge-delete-btn > .fi-icon:hover,
.fi-fo-tags-input-tags-ctn .fi-badge.fi-color-primary .fi-badge-delete-btn > .fi-icon:focus-visible {
    color: <a visibly distinct darker shade, e.g. #6E2728>;
}
```

The same applies to the hand-rolled `.clarity-chip-btn`, whose existing
`hover:text-danger-600 dark:hover:text-danger-400` classes are Tailwind utilities and must be
verified to still win after the new rule lands — `danger` is a healthy ramp, so keeping that hover
is both possible and desirable.

### Gate evaluations recorded at this boundary

- **Blast radius** — `[]`. Re-verified against the sensitive globs (`database/migrations/**`,
  billing jobs/resources, Shield/Policies/auth config, `Clickhouse*Purge|Cleanup`, `PruneScans`).
  No node matches. No approval line required.
- **Sizing gate** — does NOT fire. Four hand-edited source files (under the >6 threshold). Two
  distinct concerns, but a split is rejected: the operator scoped ACs 1-9 into this single hotfix
  and answered all three blocking questions specifically to make the whole of it shippable, both
  defects are QA'd in one browser session on one seeded zone, and splitting would cost a second
  `public/build` rebuild and review against a git-tracked directory. Recommendation carried from
  the arbiter, agreed by the driver.
- **CH-at-scale perf gate (Phase 3.5)** — not applicable. `prep-context.data_impact.ch_tables` is
  empty and no ClickHouse surface is read or written, so `perf_validation.required = false`.

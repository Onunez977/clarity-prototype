Score: 93/100

# Adversarial review — DEV-2594 (in-session lane claude1)

Against `b28821cf6`…HEAD. Vue-only Scout overlay/sheet stacking fix.
Test result handed by driver: no PHPUnit run (UI-only); `npm run build` succeeded.

## Verdict
Fix addresses the reported newest-Live-Hits / map-marker open failure on desktop and compact. Root cause class (controls covering Live Hits when `--scout-controls-h` is stale; Hit Details hidden under expanded Live Hits on compact) is treated at the class level, not only the demo symptom.

## Scoring findings
None.

## Non-scoring findings
1. **Sibling overlay parity (DayOverlay / TripOverlay)** — still `z-20` and `+ 24px` under `--scout-controls-h`. The shared height observer fix helps them; they do not get the z-[26]/32px belt. Out of ticket AC (Live Hits stack). Optional follow-up.
2. **No automated click pin** — no Vue/Playwright test asserts newest-row → sheet open. Adequate for this surface only if browser QA covers desktop + compact + marker. Residual for QA phase.
3. **Auto-collapse does not persist** — selecting a hit sets `liveHitsExpanded=false` without writing localStorage (good for auto). User's next expand still honors persisted choice. Intentional.

## Exhaustiveness (class: overlay click-steal / sheet obscured)
| Site | Verdict | Reason |
|---|---|---|
| DashboardOverlay Live Hits | fixed | z-[26], gap 32, collapse-on-select, scan_id key |
| ScoutControlsWidget height | fixed | watch(rootEl) attach; was permanently skipped when mounted compact/admin |
| MapShell BottomSheet host | fixed | z-[45] above chrome z-40/26/25 |
| BottomSheet contentKey | fixed | re-anim when selection changes while open |
| ScannerMap setSelectedHit (markers) | safe | covered by selectedHit watch → collapse |
| DayOverlay / TripOverlay left rail | residual | same stacking idiom; out of AC |
| focusHit null lat/lng | safe | still setSelectedHit; sheet opens; collapse reveals it |

## AC mapping
- Reproduce / root cause: yes (stale height + z-order + sheet under chrome)
- Every card opens including newest: addressed for Live Hits list + markers
- Desktop + compact: both paths touched
- Test integrity: N/A clock; no new PHPUnit; build green

## Unverifiable From Here
- Live demo reproduction against Premier Adjusters data (needs Scout session + seeded hits) — verify_by: browser smoke on /scout

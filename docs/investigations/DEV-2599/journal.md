# Journal — DEV-2599

- 2026-09-22T18:42:00Z intake: route=feature app=clarity repo=insight-lpr/platform base=main. Parent DEV-2224. Related DEV-2231 is text fields only. No Scout feature branch carries this image story (recovery-probability and otto-scout stacks are unrelated).
- 2026-09-22T18:42:00Z intake: fetched origin/main. Local snapshot was 3 commits behind. HEAD for this run is 845f656c (DEV-2594 live-hit click stacking is on main). Branch claude/DEV-2599.
- 2026-09-22T18:46:07Z prep: the scan the dispatcher sees is not on the Live Hits list row. The row is plate + time + type. Year/make/model and both images render on the shared Hit Details sheet in MapShell, opened by DashboardOverlay selectHitFromCard → mapStore.focusHit. Vehicle image is h-48 object-cover (cropped). Plate is h-48 object-contain. Neither is clickable. scan_img() already returns the only capture URL (VehicleImageTypeEnum p|c). No lightbox component exists. Admin org map and driver view share this sheet (adminMode does not fork the images).
- 2026-09-22T18:46:07Z prep: DEV-2594 (on main) wraps that sheet at z-[45] and collapses the Live Hits list when selectedHit is set. A later enlarge overlay must sit above that sheet and must not clear selectedHit, call setRange, or re-expand the list.
- 2026-09-22T18:46:07Z prepped: gate=CLEAN confidence=88. AC-2 is an OR; the capture URL already on the payload satisfies "full capture resolution" without a second image pipeline or a zoom-library choice.
- 2026-09-22T18:46:07Z gated: no material open questions. List-row vs sheet is settled by the ticket text (image plus vehicle description too small to match an F-350) and by DEV-2231's definition of the live-hit card as the surface that shows vehicle description. That surface is MapShell Hit Details.
- 2026-09-22T18:46:07Z planned: t1 ScanImageLightbox teleported above the sheet, wired only from MapShell; t2 Playwright on the admin org map. Blast tag shared-map-shell: map-marker hits use the same images. Trip/Day sheets stay out. Stop at phase=planned. CLI provision reserved for implement.
- 2026-09-22T18:55:11Z provisioned via qa-prep provision-only, CH deferred.
- 2026-09-22T19:13:48Z implemented: ScanImageLightbox teleported at z-[60], MapShell image buttons with local enlargedSrc cleared on hit identity change. npm run build stamped public/build and clarity-scout-sw.js. Playwright `tests/e2e/dev-2599-enlarge-scan-image.spec.ts` against https://clarity-claude_dev_2599.test: 1 passed (3.6s). diff_base 71122f90f90ecef5a192264c996395e6b6470042. t1 done, t2 done. Next Sam. PR 433 stays draft.

## 2026-09-22T20:21:34Z — Merry verify
AC fold-in: 4/4 SATISFIED. Human QA done. Rework-gate light-path OK. Build Record next.

## 2026-09-22T20:26:10Z — Merry done
QA torn down (ledger deleted). phase=done. Checkpoint next.
2026-09-22 15:26 CDT · done · Build Record + QA Ready + tear down

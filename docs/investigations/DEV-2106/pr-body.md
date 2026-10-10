<!-- ticket-status -->
**Ticket:** [DEV-2106](https://insightlpr-team.atlassian.net/browse/DEV-2106)
**Phase:** handoff — awaiting human QA · **Route:** feature · **Mode:** normal
**Done:** intake · prep+gate · plan · implement · review (RN1 94→AC8 fix→RN2 100) · verify (agent-QA AC-1..5 ✅) · QA edits (Dalton): bottom sheet full-by-default + My-Location above-right + **single-open accordion for top overlays on mobile** (RN3 90→rotation-persistence fix)
**Next:** Dalton runs human QA (device matrix) → on completion: undraft + Build Record + Jira → In Code Review + QA teardown
**Open questions:** none
**Resolved questions:** Hit-markers key → hide on compact · Bottom bar → tap-handle only · Exec → full /ticket pipeline · JS verify → build + browser QA (no new deps) (all human:Dalton 2026-06-30)
**Artifacts:** docs/investigations/DEV-2106/ · QA guide: .context/qa-human-guide.html
<!-- ticket-claim: dalton.mccleery@insightlpr.com @ 2026-06-30T15:25:12Z -->
<!-- /ticket-status -->

---

Scout driver app: map overlays + bottom stat bar **map-first** on small screens (auto-collapse to chips below `lg`=1024px, expand on tap, persisted choice; desktop unchanged). Admin Org Map / Bird's Eye untouched.

**Changes:** new `useViewport` + `useCollapsible` composables; `ScoutControlsWidget` (chip), `DashboardOverlay` (Live Hits pill, Hit-markers key hidden on compact, Map Layers icon-chip, Legend default-collapsed, responsive error-toast offset), `App.vue` bottom bar (tap handle, admin-gated), `BottomSheet` width clamp. Widths viewport-clamped; `pointer-events` pass-through preserved. Rebuilt + committed `public/build` assets.

**Review:** RN1 94 → fixed AC8 (bottom-bar collapse leaked into admin mode) → RN2 **100/100**.
**Agent QA:** AC-1..5 SATISFIED (desktop expanded, mobile chips, tap toggle, no 375px overflow, boot clean). AC-6/7/8 deferred to human device-emulation. Stale committed build assets caught + fixed.

**Still in draft** pending human QA of the device matrix (375/430/932/810/1080/1440) — see `.context/qa-human-guide.html`.

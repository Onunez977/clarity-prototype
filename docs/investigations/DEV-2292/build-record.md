Insight AI — Build Record
<!-- build-record: DEV-2292 -->

**Route:** bug · **Base:** main · **PR:** https://github.com/insight-lpr/platform/pull/300

## What changed & why
Single file: `resources/js/clarity-scout/App.vue` (+ rebuilt `public/build` assets; `public/build` is tracked).

**Root cause.** On the admin Scout Org Map (Bird's Eye), when the org is active today but no kit is reporting a live GPS position, App.vue renders a *centered* notice card (added in DEV-2197) with no close control. A customer (Shane Freitas / Accurate Adjustments) reported it as a stuck modal blocking the map. A sibling *bottom amber pill* carries the same message as a persistent, non-blocking signal.

**Fix.**
1. Added an in-memory `noticeDismissed` ref (component-scoped; no persistence, no watcher).
2. Gated the **centered card** on `noKitsReporting && !noticeDismissed` and added a ✕ close button (Toast dismiss convention: `type=button`, `aria-label="Dismiss"`, `pointer-events-auto` so it's clickable while the card container stays `pointer-events-none` — the map remains draggable underneath).
3. The ✕ sets `noticeDismissed = true`. Session-scoped: a plain ref resets on the next App mount (page reload / new SPA session).
4. The `noKitsReporting` computed is unchanged, so AC-3 (auto-hide when kits report) is preserved by reactivity.

**QA-driven refinement (Dalton, during manual QA):** the ✕ dismisses **only the centered modal card**; the **bottom amber pill stays** as the persistent no-GPS signal (reverted the pill's `v-if` to `noKitsReporting` only). This matches the pill's original DEV-2197 intent as the persistent feed indicator.

## Resolved questions
- Dismissal design → keep the centered card + add a ✕ (Toast convention) · human:Dalton 2026-08-03
- Re-arm (AC-4) → session-scoped; only a page reload / new session clears it (no re-arm on state change) · human:Dalton 2026-08-03
- QA refinement → ✕ dismisses only the centered card; the amber pill persists · human:Dalton 2026-08-03 (QA)

## Acceptance criteria
- [x] **AC-1** User can dismiss the "No kits reporting GPS" notice at any time — ✕ on the centered card (agent-QA A1/A2; RN2 review; human QA).
- [x] **AC-2** After dismissal, the map is fully interactive with no obstruction — centered card removed; map center resolves to the Maps pane, a map control was clickable; the pill is bottom-anchored/non-blocking (agent-QA A2/A3).
- [x] **AC-3** If kits begin reporting GPS, the notice disappears automatically — unchanged reactive `noKitsReporting` computed (verified by review; browser reproduction deferred as it needs a live sc_positions row mid-session).
- [x] **AC-4** Notice does not re-appear until next session or a meaningful state change — session-scoped in-memory ref; reload re-shows it, stays dismissed within the session (agent-QA A4).

## Tests
No JS/Vue test runner in this repo (frontend-only change; `public/build` committed). PHP suite unaffected and not auto-run locally (CLAUDE.md execution policy — CI runs it on push). Verification was adversarial review + agent/human QA. `npm run build` succeeded; the compiled `clarity-scout`/`birdseye` bundles were confirmed to carry the change.

## Review
- **RN1:** 1 lane (opus, in-session-agent — nested `claude -p` was unauthenticated under the harness, so the lane ran via `insightlpr:adversarial-reviewer`; recorded honestly as `in-session-agent`). **Score 100/100**, 0 scoring defects.
- **RN2** (after the QA-driven pill-persist refinement): 1 lane, **100/100**, verified in source + compiled bundle.
- Residual (non-blocking, cosmetic, pre-existing): centered card not `role="dialog"`; icon SVGs lack `aria-hidden`; ✕ lacks an explicit focus-visible ring. Posted as a non-blocking PR comment; no follow-up filed (nits).

## QA performed
- **Env:** real local Docker ClickHouse (`clarity-qa-clickhouse`) + isolated Postgres branch DB `zod_claude_dev_2292` + Herd site `clarity-claude_dev_2292.test`, dev-login. Provisioning: bootstrap already-provisioned; migrations already-migrated; Docker already-running; CH container created; frontend build ran.
- **Seed:** reused `LocalDataSeeder` org 2 + admin user 1 (Administrator role, Pro Scout feature); added a `platform.kit_settings` row for kit 1 (PG tinker); inserted 3 `clarity` `scans_hot` rows dated today for `kit_id=1/org_id=2` (CH `seed.sh`); left `sc_positions` empty. This makes `noKitsReporting` true on `/scout/org-map`.
  - Read-only verification (as admin id 1): `GET /scout/org-map/api/drivers` → 15; `.../map/driver-positions` → 0; `.../dashboard/stats?range=today` → `scans_today: 3`. All three preconditions hold.
- **Agent QA:** 1 browser lane, A1–A4 all ✅ (notice + ✕ on load; ✕ hides the card; map clear after; re-shows on reload). No console errors, no 4xx/5xx.
- **Human QA:** interactive guide rendered + reviewed by Dalton; the pill-persist refinement came out of that pass and was re-verified (RN2).
- **What changed because of QA:** the pill-persist refinement above.
- **Open findings:** 🟡 pre-existing, unrelated to DEV-2292 — some sibling Bird's Eye ClickHouse queries hardcode the literal `clarity.` DB prefix and error against the per-worktree CH DB (`clarity_claude_dev_2292`). This diff touches zero ClickHouse files; the core notice feeds returned 200. Not a regression, not actionable here.

## How numbers were derived
No product-data derivations (frontend-only change). The only "numbers" are the QA seed verification counts above (drivers=15, positions=0, scans_today=3), which are QA-fixture counts, not shipped values.

# DEV-2232 — Implementation Plan

**Route:** bug · **Base:** main · **Scope:** single-file backend guard + pinning tests · **Data:** 100% Postgres (no migration, no ClickHouse)

## Subtask t1 (complex — auth/analytics-integrity territory)

**Guard lazy shift-open + ShiftStart emit against force-login impersonators in `status()`.**

- `app/Http/Controllers/Api/ClarityScoutShiftController.php` — in `status()`, add an early branch on `$user->isScoutForceImpersonator()`. When impersonating: skip `EnsureOpenShift::currentOpenForKit()` and the `ShiftStart` analytics/audit emit entirely, and return the controller's existing "no open shift" JSON payload (match the real shape in the file). Normal drivers keep the unchanged path.
- Use the existing `User::isScoutForceImpersonator()` (SoT) — do not inline a fresh ability check.

**Tests** (`ClarityScoutShiftControllerTest`, TDD red→green):
1. Impersonator token `['*','clarity-scout-force']` + fresh matching Postgres scan activity + no existing shift → `/shift/status` returns no-open-shift shape, `KitShift::count()` unchanged, `Analytics::track` never called with `ShiftStart`.
2. Regression: normal `['clarity-scout']` token + same scan activity → a `kit_shifts` row IS created and `ShiftStart` fires once (proves AC2).

## Acceptance criteria mapping
- **AC1** ← test 1 (no row, no event for impersonator).
- **AC2** ← test 2 (normal driver unchanged).
- **AC3** ← backend fix returns `open:false`, so the PWA End Shift button (gated on `shift.open`) stays hidden while impersonating; verified in QA.

## Excluded (with reasons)
- `end()` guard — out of scope per Dalton (start only); **known residual**.
- Frontend `!auth.isImpersonating` guard — out of scope per Dalton (backend-only).
- `EnsureOpenShift` internals, `CloseInactiveShiftsCommand` — not impersonation paths.

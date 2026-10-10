# Insight AI — Build Record · DEV-2593

## What changed
1. Soft-delete collision UX + **Restore deleted Scout user** on Scout Users (role + clarity-scout + invite when unverified).
2. Scout invite link TTL **7 → 3 days** (`ScoutOnboardingNotification::$linkTtlDays`).
3. **No** `scout_onboarding_nonce` — resend does not hard-invalidate prior links; dual-valid links within the 3-day window are an accepted tradeoff.

## Why
SoftDeletes hid trashed emails from Eloquent unique checks. Resend existed but operators needed Restore + clearer collision copy. Hard revoke via DB nonce was dropped after QA review in favor of a shorter TTL.

## How derived
- Gate originally 2A nonce; **pivoted in QA** to TTL-only (Dalton).
- Review RN3 PASS then rework; focused PHPUnit green after drop.

## AC checklist
| AC | Status | Evidence |
|----|--------|----------|
| AC1 Resend | SATISFIED | Filament resend |
| AC2 Link lifetime | SATISFIED | 3-day signed URL (no hard revoke) |
| AC3 Soft-delete collision | SATISFIED | named Restore copy |
| AC4 Restore | SATISFIED | restore_scout_user |
| AC5 Named action | SATISFIED | collision messages |

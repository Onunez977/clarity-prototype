# DEV-2593 Plan

## Settled decisions

- **1A** Soft-deleted email → detect + offer Restore / Resend / Re-enable (no partial unique index).
- **2A (superseded)** Invite TTL 3 days; resend does not hard-revoke prior link (dual-valid within TTL). Dropped `scout_onboarding_nonce`.
- **3B** Keep Scout Access toggle; add Restore on Scout Users for soft-deleted drivers.

## Subtasks

1. Soft-delete collision copy + Restore action (Scout Users + Users scoutContext).
2. Invite TTL 7→3; resend modal / expired-page copy.
3. Soft-deleted Scout row wash (same as Users/Zones / Scans invalid).
4. Tests.

## Out of scope

Scout password reset / account recovery; Invite model; force-delete; partial unique on `users.email`; hard revoke on resend.

## Provision note

Worktree `.env` / `.env.testing` already point at `zod_claude_dev_2593` / `zod_testing_claude_dev_2593` — treat Postgres as provisioned; no Claude spawn.

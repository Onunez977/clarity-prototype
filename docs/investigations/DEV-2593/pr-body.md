<!-- ticket-status -->
**Ticket:** [DEV-2593](https://insightlpr-team.atlassian.net/browse/DEV-2593)
**Phase:** prepped · **Route:** bug · **Mode:** normal
**Done:** intake · prep (escalated best-of-N, gate BLOCKED)
**Next:** answer gate questions, then `/ticket DEV-2593 --resume`
**Open questions:**
1. AC2 — kill old invite link on resend, or allow overlap until password set?
2. Soft-delete — free email for a new user (partial unique index), or restore-only guidance?
**Resolved questions:** none yet
**Not in this ticket:** sibling password-reset / account recovery; zone/vehicle; force-login
**Artifacts:** docs/investigations/DEV-2593/
<!-- ticket-claim: daltonmccleery@gmail.com @ 2026-09-17T16:57:00Z -->
<!-- /ticket-status -->

## Summary
WIP — Scout invite / soft-delete email reuse.

## Prep notes
Resend already exists (`resend_onboarding`). Blocking bug: soft-deleted `users.email` still unique. Gate parked on AC2 + recreate-vs-restore.

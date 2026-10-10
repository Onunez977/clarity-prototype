# DEV-2593 — review round 2 findings

**Score:** 76/100 · **Verdict:** FAIL · **Source:** in-session adversarial-reviewer

## Scoring mediums (all remediated for RN3)
1. bumpScoutOnboardingNonce refresh race → lockForUpdate transaction, no refresh
2. nonce default -1 broke legacy links → default 0
3. EditScoutUser unreachable trashed branch → hydrate clears assigned_user_id; mutateData keeps kit_settings.user_id
4. UsersResource Scout copy leak → scoutContext: ScoutUserProvisioner::canAccess()
5. Missing org-scope unit coverage → UserScoutEmailCollisionMessageTest

## Scoring lows (remediated)
6. Restore without transaction → DB transaction + post-commit notify
7. Restore silent guard → danger notification
8. signedSetPasswordUrl typing → typed params; helpers last
9. Stale nonce POST untested → test_stale_nonce_post_cannot_set_password

## Non-scoring lows (noted, not blocking under functional policy)
- Restore reuses ScoutUserWebAccess analytics event
- No visual Deleted badge on kit row

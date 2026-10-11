# DEV-2737 claude-plan-compare (preflight #23)

## Convergences
- Verify-first / no product PHP unless measure proves wrong and fix is explicitly scoped; prefer follow-on ACs (AC-3).
- Path: `lpr-repossessions` → `LprRepossessions` → This Month = last bucket of `monthlySeries` `db_repos` (not `windowSql`).
- SQL: `billing_claim_events_v` + `BillingClaimDedup` + status-3 hotlist + status-log −7; org on forwarder **or** agent; Cloud CH READ-ONLY.
- V1 pin exact UI integer; V4 lift SQL from app / explainer; V5 run on Cloud unmodified.
- Preflight #24 simple wins; #25 does_not_fire (verify, not path rewrite).

## Divergences / gaps
- Claude draft did not yet have a live Cloud number. Aragorn ran Cloud CH RO measure (2026-09-28): Sep `db_repos` = **533** vs QG UI ≈ **509**.
- Claude emphasizes lifting SQL from live explainer (V4) before measure; Aragorn used code-mirrored monthlySeries shape (same predicates). Follow-up Implement/Verify should still capture V1 exact UI + optional explainer dump for zero-drift.

## Adopted
- Claude’s path table and “This Month = monthly series last bucket” (critical — do not measure with windowSql alone).
- Claude’s V1→V5 order; M2 localiser only if mismatch.
- Aragorn measure packet as plan evidence: `cloud-measure-org14-sep2026.md` + `.jsonl`.
- Decision: treat as **mismatch pending V1 re-pin**; plan product_php=false; Implement/Verify documents proof or files follow-on ACs — no invent fix PHP in this plan.

## Rejected
- Inventing product PHP or a “correct” integer other than measured 533 / UI ~509.
- Treating ~509 as exact Cloud truth.

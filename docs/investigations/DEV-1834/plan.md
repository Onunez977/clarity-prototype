# DEV-1834 — Plan

**Deliverable:** provider-facing documentation of every RDN Statistics metric Clarity publishes to the RDN SQS queue. Documentation-only (no code changes).

## Decisions (from the gate)
- **Location:** new `okf/concepts/rdn-provider-integration.md` (repo canonical wiki, beside the engineering-facing `rdn.md`).
- **Scope:** document strictly the **5 currently-published hour-grain metrics**. No Phase-2 / day-month / agent-level mention.

## Subtask DAG
Single node (`t1`, simple):

**t1 — Author the provider spec.** Files: `okf/concepts/rdn-provider-integration.md` (new), plus a See-also link in `okf/concepts/rdn.md` and an index row in `okf/index.md`.

Sections:
1. Overview & audience (RDN integration engineers).
2. Delivery model — hourly at :05, one SQS message per (org, metric_type, completed UTC hour), up to 5 per eligible org/hour; eligibility (rdn_id + Pro tier + opt-in flag); at-most-once guarantee + idempotency_key rationale; transport (SQS, region/profile/queue config).
3. Shared envelope table — `metric_type`, `metric_range_start`, `metric_range_end`, `rdn_org_identifier`, `idempotency_key`, `data` — type / units / nullability / example / semantics.
4. One subsection per metric type (all 5) — what it counts + full example payload.
5. `rdn_org_identifier` format (opaque, RDN-issued, ≤64 chars).
6. `metric_range_start` / `metric_range_end` semantics (inclusive start, exclusive end, UTC, completed hour).

All facts sourced from the VERIFIED `prep-context.json` (metric_catalog / envelope / delivery_model). Synthetic example values only — no real data.

## Gates
- **Tests:** none — a markdown artifact has no programmatic surface (CLAUDE test-enforcement is for code).
- **Perf gate:** not required (no new query).
- **Provision (3.5) / Verify (6):** not applicable — docs-only, no runtime/DB/env to stand up.
- **Review (5):** the real quality gate — adversarial check of every documented fact against the source files in `prep-context.grounding.key_files`. Never skipped.

---
type: DataModel
title: ai_usage_logs (AiUsageLog)
description: Per-org LLM/AI usage and cost ledger for AI agent calls, in the PostgreSQL zod database, platform schema.
resource: app/Models/AiUsageLog.php
tags: [model, postgres, platform, ai]
timestamp: 2026-07-06T00:00:00Z
---

# ai_usage_logs

Each row records a single **AI/LLM call made on behalf of an [organization](../concepts/organization.md)** — which agent class invoked it, the provider and model, prompt/completion token counts, and an estimated dollar cost. It is an append-only usage/cost ledger used to attribute and total AI spend per org, agent, and time period. It is a Clarity add-on concern, independent of the core LPR scan/hit flow.

The model lives in **PostgreSQL** — logical database `zod`, `platform` schema (the Clarity add-on schema), `platform` connection (the default). It has a polymorphic `loggable` link so a usage row can be attached to whatever record triggered the AI call.

## Location

- Database: `zod`
- Schema: `platform`
- Connection: `platform`
- Table: `ai_usage_logs`
- Model class: `App\Models\AiUsageLog` (`app/Models/AiUsageLog.php`)

## Columns

| Column | Type | Null | Description |
|--------|------|------|-------------|
| `id` | `bigint` | no | Primary key (sequence). |
| `org_id` | `bigint` | no | Owning [organization](../concepts/organization.md) the usage is billed to. FK → `public.orgs.id`. |
| `agent_class` | `character varying(255)` | no | The AI agent class that made the call (filtered by `forAgent`). |
| `provider` | `character varying(255)` | no | LLM provider (e.g. `openai`). |
| `model` | `character varying(255)` | no | Model name, normalized to a base name (dated suffixes like `-2024-07-18` stripped). |
| `prompt_tokens` | `integer` | no | Input token count. Defaults to `0`. |
| `completion_tokens` | `integer` | no | Output token count. Defaults to `0`. |
| `estimated_cost` | `numeric(10,6)` | no | Estimated USD cost computed from token counts and `config('ai.pricing.<model>')`. Defaults to `0`. |
| `loggable_type` | `character varying(255)` | yes | Polymorphic type of the record that triggered the call. |
| `loggable_id` | `bigint` | yes | Polymorphic id of the triggering record. |
| `created_at` | `timestamp(0) without time zone` | yes | Row creation time (used by `forPeriod`). |
| `updated_at` | `timestamp(0) without time zone` | yes | Row update time. |

## Casts & enums

- `prompt_tokens` → `integer`
- `completion_tokens` → `integer`
- `estimated_cost` → `decimal:6`

## Relationships

- **loggable()** — morphTo; the record that triggered the AI call (polymorphic)
- **org()** — belongsTo [Org](../concepts/organization.md) via `org_id`

## Data mapping (PostgreSQL ↔ ClickHouse)

No ClickHouse replica (a `platform`-schema operational ledger; the PeerDB CDC path replicates `public.*`, not `platform.*`). Mapping not established.

## Notable scopes & behavior

- `$guarded = []` — fully unguarded; rows are written via the `logUsage()` factory helper.
- `forOrg(int $orgId)` — org-scopes by `org_id` (see [Multi-Tenancy](../concepts/multi-tenancy.md)).
- `forPeriod(string $from, string $to)` — filters `created_at` between two datetimes.
- `forAgent(string $agentClass)` — filters by agent class.
- `logUsage(...)` static factory — normalizes the model name, computes `estimated_cost` via `calculateEstimatedCost()` (token counts × per-million pricing from `config('ai.pricing.*')`), optionally attaches the polymorphic `loggable`, and persists the row.

## See also

- [Organization](../concepts/organization.md) — the billed tenant
- [Multi-Tenancy](../concepts/multi-tenancy.md) — `forOrg()` scoping

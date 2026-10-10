---
type: Concept
title: Violent Debtor
description: RDN pushes violent-debtor records to Clarity. Clarity stores them in PostgreSQL zod.public keyed by plaintext VIN, with Laravel-encrypted first and last names in separate columns and a raw JSON blob of the full payload, and flags that VIN on hotlist, hit, and scan.
tags: [rdn, integration, postgres, violent-debtor]
timestamp: 2026-10-02T19:21:00Z
---

# Violent Debtor

A **violent debtor** is a person RDN has marked as violent on a vehicle identified by VIN. RDN pushes those records to Clarity. Clarity keeps the list so that, given a VIN, it can answer whether that VIN is a violent debtor and show a flag wherever that VIN appears.

This is a receive path. It is separate from the RDN SOAP pulls and the hourly metrics push on [RDN Integration](rdn.md). The table and the webhook are **not in this repo yet**.

## Identification

Lookup is by **VIN**, in plaintext.

| Rule | Detail |
|------|--------|
| Key | VIN, plaintext, unique |
| Question the list answers | Is this VIN a violent debtor? |
| Search | VIN is the searchable field. Names are not a search key. |
| Write | Upsert on that unique VIN |

## Classification

RDN describes each record with a small payload. These fields are the ticket sketch of what RDN posts. `violence_level`, `notes`, and `created_at` are **not** locked as their own columns; they travel inside the raw payload blob (see Storage).

| Field | Sketch |
|-------|--------|
| `vin` | string. Identity key. Stored plaintext. |
| `first_name` | string. Stored Laravel-encrypted, own column. |
| `last_name` | string. Stored Laravel-encrypted, own column. |
| `violence_level` | integer enum `1`, `2`, `3` |
| `notes` | string |
| `created_at` | date-time string |

`violence_level` is the classification RDN sends (1, 2, or 3). Clarity does not derive a level of its own.

## Ingest

Clarity exposes an **open HTTP webhook**. RDN POSTs the record to it.

Auth is a long GUID, or a comparable string, kept in the environment. The request carries that string on the path (or an equivalent placement on the URL). Clarity accepts the call when the presented string matches the environment value. There is no user session and no database lookup for the check. Rotation is changing the environment value.

The exact URL template, the environment variable name, error bodies, and re-POST idempotency rules wait on the push contract. See Status.

Inbound bodies are kept: the raw JSON blob is the stored copy of the payload, so a received post can be inspected.

## Storage

Platform writes the list into PostgreSQL **`zod.public`**. The ticket name for the table is like `violent_vin`. **That table does not exist in this repo** (no migration, model, or SQL). This repo owns the write. The table is not added through `insight-lpr/laravel-zod-migrations`.

That home is an accepted exception to [ADR-004](architecture-decisions.md) for this ingress. ADR-004 keeps core `zod.public` with `laravel-zod-migrations` and puts Clarity add-ons in the `platform` schema ([Data Architecture](data-architecture.md)). ADR-004 stays Accepted; this page does not amend it.

Locked columns, beside each other on the row:

| Stored | Form |
|--------|------|
| VIN | Plaintext, unique |
| First name | Laravel-encrypted, **its own column** |
| Last name | Laravel-encrypted, **its own column** |
| Full payload | **Raw JSON blob** of the payload RDN posted |

First and last name are separate columns so those two columns can be dropped if the privacy stance on debtor names changes. The rest of the payload stays in the raw blob next to them.

No ClickHouse mirror is part of this design.

## Display

The first display is a **flag only**, on the hotlist, the hit, and the scan, when that VIN is shown. The flag says the VIN is a violent debtor. It stays light: present, not a second detail panel.

Level and threat detail (what the `violence_level` was, what kind of threat) are later questions. They are not part of the first display.

## Status

Implementation waits on the push contract — [DEV-2742](https://insightlpr-team.atlassian.net/browse/DEV-2742) — for the URL, GUID lifecycle, error shape, and idempotency. The storage and display rules above are already locked.

On the 2 Oct 2026 Clarity discussion, this ingest was named the first product priority among the items discussed. That ranking is discussion context.

## Out of scope

- Patroller UI — [DEV-2741](https://insightlpr-team.atlassian.net/browse/DEV-2741)
- RDN's outbound client (RDN builds that)

## UNPROVEN (do not promote to rules)

- **Own columns for `violence_level`, `notes`, or `created_at`.** Those fields are on the ticket sketch and therefore inside the raw JSON blob. Promoting any of them to its own column is not locked.
- **Deeper display.** Viewers may later ask for the violence level or what the threat was (weapon or otherwise). The first display is the flag. Visual treatment of the flag (color, icon, copy) is not locked.
- **Exact webhook URL and environment variable name.** The mechanism is locked: an env-held GUID or comparable string, matched on the request, rotated by changing the env value. The path-versus-other placement, the variable name, minting, error bodies, and idempotency responses belong to the DEV-2742 contract.
- **A log sink besides the raw blob.** The blob is the stored payload. A separate application log or admin viewer is not specified.

## Where it lives in code

Nowhere yet. A repo search at this rewrite found no `violent_vin` table, violent-debtor model, or receive route under `app/`, `database/`, or `tests/`.

The ticket names a project doc `docs/rdn-violent-debtor-api.md`. That file is not in this repo.

## Sources

- [DEV-2893](https://insightlpr-team.atlassian.net/browse/DEV-2893) — Clarity receive endpoint
- [DEV-2740](https://insightlpr-team.atlassian.net/browse/DEV-2740) — parent epic, violent-debtor flag on hotlist / hits
- [DEV-2742](https://insightlpr-team.atlassian.net/browse/DEV-2742) — push contract (URL, GUID lifecycle, errors, idempotency)
- Clarity discussion, 2 Oct 2026 — purpose, VIN lookup, env-matched webhook string, separate encrypted name columns, flag-first display, later level/threat questions

## See also

- [RDN Integration](rdn.md) — SOAP pulls and the hourly metrics push
- [Data Architecture](data-architecture.md) — `zod.public` versus the `platform` schema
- [ADR-004](architecture-decisions.md) — schema split this ingress is an accepted exception to
- [Repositories](repositories.md) — `laravel-zod-migrations` owns other `zod.public` tables; this one is written from platform

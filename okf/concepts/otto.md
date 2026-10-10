---
type: Concept
title: Otto (Clarity assistant)
description: Admin /app chat assistant — laravel/ai + Bedrock, Soketi Echo, Horizon {otto}, customer KB separate from okf, turn lifecycle, page-action proposals, investigation hydration, Scout driver lookup.
resource: docs/plans/otto.md
tags: [product, otto, ai, realtime]
timestamp: 2026-09-01T00:00:00Z
---

# Otto (Clarity assistant)

Otto is Clarity’s conversational LPR assistant on the **admin `/app` panel only** (not the client portal). It searches org-scoped scans, hits, and hotlist assignments, looks up Scout driver locations when available, surfaces interactive widgets, and answers customer-safe product questions.

## Source of truth

| Layer | Where |
|-------|--------|
| Dense AI / architecture reference | [`docs/plans/otto.md`](../../docs/plans/otto.md) — update in the same change when tools, prompt, widgets, events, or frontend change |
| Engineering domain wiki | this page + rest of `okf/` |
| Customer-facing knowledge Otto may quote | `resources/otto-knowledge/` — **not** this wiki |

Do **not** put ClickHouse/Postgres/CDC/impersonation/RDN internals into `resources/otto-knowledge/`.

## Access gates

Both required:

1. Org: `org_meta.properties.otto_enabled` (`Org::ottoEnabled()` / `enableOtto()`)
2. Permission: Spatie `use-otto` (seeded by `app:seed-permissions`)

Staff QA: `admin_impersonator` session + org enabled grants Otto even without `use-otto`. `Chat::conversationId` is `#[Locked]`; `send` / `stop` / `pollTurn` and `RunOttoChat` also require the conversation to belong to the acting user.

## Stack (executable pointers)

- **AI:** `laravel/ai` + Bedrock (`config/ai.php` / `config/otto.php`). Use IAM SigV4 via `access_key_id` / `secret_access_key` (`BEDROCK_*` or `AWS_BEDROCK_*`). **Never** put an IAM access key in laravel/ai’s `key` field — that field is bearer API-key auth, not IAM.
- **Mount:** App panel `LAYOUT_END` → `components.otto.popout-mount` (see `docs/plans/otto.md`).
- **Jobs:** `RunOttoChat` on Horizon queue `{otto}` (`supervisor-otto`). Locally `QUEUE_CONNECTION=redis` (not `sync`) so Horizon actually runs the job. If pending jobs never drain while supervisors look running, see [PG-011](platform-gotchas.md).
- **Channels:** private `otto-thread.{conversationId}`, `otto.{userId}.{conversationId}` — session auth via `/broadcasting/auth` (`withBroadcasting()`; catch-all registered later in `AppServiceProvider` booted — [PG-033](platform-gotchas.md)).
- **Thinking hang-net:** if Echo never delivers `stream_end`, `otto-stream.js` polls `Chat::pollTurn()` until `RunOttoChat` drops `otto:pending_prompt:{id}`, then hydrates assistant bubbles (does not re-inject a user prompt already painted in the live DOM).
- **Send:** optimistic DOM bubble + single `Chat::send()`. `OttoUserMessagePosted` is `->toOthers()`; the sending tab ignores Echo of its own prompt. `send()` is a no-op while a turn is in-flight.
- **KB search tool:** indexes `resources/otto-knowledge/`.
- **Assignment search (DEV-2319):** `HotlistSearchTool` queries ClickHouse `clarity.hotlist` through `Hotlist::visibleToOrg($orgId, $stagingOrgIds)` — composes `forOrg` (direct) with `staged()` + `org_id IN Org::stagingOrgIds()`. Hydration in `InvestigationDataController` uses the same scope. This is the book of work (“what are we looking for?”), not hit events (“when did we see it?”). No default 30-day window.

## Turn lifecycle

One turn = one `RunOttoChat` job on `{otto}`. `RunsOttoStream::handle()` owns the shape; `RunOttoChat` fills in the hooks.

| Cache key | Meaning |
|-----------|---------|
| `otto:run:{conversationId}` | Per-conversation lock, TTL **210s**. Not acquired ⇒ the job redispatches itself after 3s rather than running concurrently. |
| `otto:pending_prompt:{conversationId}` | **The turn-completion signal.** Present ⇒ in flight. `Chat::send()` no-ops while it exists; `Chat::pollTurn()` reads its absence as "this turn is done". |
| `otto:stop:{conversationId}` | Set by `Chat::stop()`; checked per stream event so Stop ends the `each()` loop. |

`RunsOttoStream::turnCache()` forces the **redis** store whenever `CACHE_STORE=file`. A file store is per-checkout, so an HTTP request in a worktree and the main Horizon worker would never see each other's keys and every follow-up send would no-op for 30 minutes.

**Ordering contract — the pending key is released last.** Because `pollTurn()` treats a missing key as "complete" and *immediately re-renders the thread from the database*, everything that turn produced must already be written when the key drops. The order in `handle()` is therefore: trim the stored reply → `onStreamComplete()` (persist the investigation, persist `meta['page_actions']`, set the title) → **forget the pending key** → broadcast `OttoInvestigationUpdated`. The `finally` block forgets the key again as a backstop for the failure path. Moving the `forget()` earlier reintroduces a poll window that renders the reply with no search widget and no proposal card.

**Completion is measured against a baseline, not against "any assistant message".** `Chat::$turnBaselineAssistantCount` is captured in `send()`; `pollTurn()` reports `complete` only once the assistant-message count grows past it. Otherwise a turn that dies before persisting a reply would find the *previous* turn's answer in the thread and report success with stale HTML — and polling exists precisely for the case where the `otto.error` broadcast cannot reach the client. A null baseline (mid-turn page reload) falls back to "any assistant message".

**Always order assistant messages by `id`, never `created_at`.** `laravel/ai`'s `DatabaseConversationStore` writes message ids with `Str::uuid7()` and orders its own reads by `id` desc; `created_at` is only second-granularity, so two rows in the same second tie arbitrarily. `latestAssistantMessageId()` is the one helper — `persistInvestigation`, `persistProposedPageActions`, and `trimNarrationFromStoredReply` must all resolve the same row through it.

**Assistant text is stripped twice, by two implementations.** `stripToolCalls()` exists in both `resources/js/alpine-components/otto-stream.js` (the live stream) and `app/Support/Otto/MessageRenderer.php` (every re-render from the database — refresh, `pollTurn`, history). They must carry the same pattern list. When they drift, text the user never saw while streaming reappears after a reload: leaked `<function=…>` / `<｜…｜>` delimiters, a hallucinated `<div class="filter-confirmation">` card, or a `**Proposed filter changes:**` bullet list. `Str::markdown(html_input: 'strip')` is **not** a substitute — it drops the tags but keeps their inner text.

## Page actions — propose, confirm, apply

Otto does not change the page. It **proposes**, the user **Accepts**, and a page adapter applies. Three participants:

1. **The tool.** `ApplyScanSearchFiltersTool` / `ApplyHitSearchFiltersTool` / `ApplyHotlistSearchFiltersTool` build `PageActionChange`s via `BuildsPageSearchFilters` and call `ProposesPageActions::proposePageAction()`, which records the action on the investigation, broadcasts `OttoPageActionProposed`, and returns a string that forbids the model from claiming anything was applied — and forbids restating the individual changes, because each already renders as its own item in the confirmation card.
2. **Persistence.** `RunOttoChat::persistProposedPageActions()` writes the actions onto the newest assistant row's `meta['page_actions']` so the card survives a refresh.
3. **The adapter.** A Livewire/Filament page uses `InteractsWithOttoPageActions` (advertise `ottoPageContextState()` after each render; handle the same-tab `otto-page-action` event) plus `AppliesOttoPageFilters` (write into `tableFilters` / `tableDeferredFilters`). `ottoPageActionAuthorized()` is the **real** authorization boundary — the client payload is only a hint.

**Deferral.** `DefersToPageSearch` makes the generic chat-search tools refuse to run in-chat when the user is already on the page that runs that exact search, forwarding the mapped arguments to that page's `Apply*` tool instead. There is **no model-controlled escape**; the only ways out are server-gated in `Chat::send()` — the "Make Otto search instead" continuation prompt, or an `InChatSearchIntent` keyword phrase.

**A filter that cannot be resolved must abort the proposal, not disappear from it.** `clientChange()` and `kitChange()` set `BuildsPageSearchFilters::$filterResolutionError`, and `proposeFilterChanges()` returns that error instead of proposing. Silently dropping an unresolvable client or kit **widens** the search the user asked for — "show Acme hits from the last 7 days" would become an all-clients search — and since the model is told not to restate the changes, nothing else would tell them their filter was discarded. `ResolvesOttoKitFilter` already returned an `error` string for the chat-side tools; the page tools surface it the same way.

## Investigations and the results pane

A conversation accumulates an **investigation** — `platform.agent_investigations` (`user_id` + `conversation_id` unique, `properties` jsonb, FK to `platform.agent_conversations`). `AgentInvestigationProperties` holds two lists: `searches` (a `searchId`, a `SearchType`, the tool arguments, a title) and `searchResults` (`searchId` → `resultId` pairs). Tools record **ids only**, capped at `LimitedToolResultMessage::SEARCH_LIMIT` (50) per search — never a warehouse total.

`GET /otto/investigation/{conversationId}` (`InvestigationDataController`, inside the `auth` group, re-checking `OttoAccess` **and** `user_id` ownership) rehydrates those ids into rows for the results pane and map: scans via `Scan::forOrg`, hits via `HitsListQuery::baseQuery`, assignments via `Hotlist::visibleToOrg`, and Scout drivers straight out of the recorded search parameters.

**Hydrate hits with the search's own window start, never `now()`.** `HitsListQuery` picks `clarity.hits_hot` for any window inside 90 days, and that table has a 90-day TTL — so passing `now()` pins every hydration to the hot table and returns nothing for ids a search legitimately found in `clarity.hits_cold`. The symptom is a chat message reporting N hits above an empty table and map. `InvestigationDataController::hitWindowStart()` takes the earliest `start_date` across the hit searches being hydrated; an open-ended or unparseable search falls through to `null`, which selects the cold table — slower, always correct. See [PG-029](platform-gotchas.md).

## Scout driver locations

`ScoutDriverPositionTool` answers "where is *X*". It soft-gates the same way Bird's Eye does (`ClarityScoutAdminMiddleware`) and returns a customer-friendly message rather than hard-failing. The `ADMIN` role is always required; `admin_impersonator` substitutes for the `CLARITY_SCOUT` feature flag only, not for the role. Positions come from `ResolveOrgDriverPositions`; each one is labelled by `ReverseGeocoder` (Geocodio → Google → Nominatim locally; cached for a day on lat/lng rounded to 5 dp, so the cost is per *distinct* location).

Two bounds that must both stay in place:

- **Positions at `0.0, 0.0` are dropped before they are counted.** They have no usable fix. Counting them made the fleet summary promise "…and N more with a recent position" for drivers whose every follow-up could only answer "no recent Scout location".
- **The `FLEET_DRIVER_SUMMARY_LIMIT` (8) cap applies on the identity path too, not just the fleet summary.** Geocoding is one blocking HTTP round-trip per *distinct* location, and a partial `driver_name` (`"a"`) matches every kit whose assigned driver contains it. Uncapped, a wide match can outlast the job's `timeout = 180` on `tries = 1` and leave the turn hung. Kits that have a position are shown first; the remainder becomes an "…and N more kits matched that name" line.

## Realtime (local vs prod)

| Side | Config |
|------|--------|
| Laravel → Soketi | `BROADCAST_CONNECTION=pusher`, `PUSHER_HOST=lerd-soketi`, `PUSHER_PORT=6001`, `PUSHER_SCHEME=http`, app id/key/secret matching Soketi (`lerd` locally) |
| Browser → Soketi | Local `resources/js/app.js` hard-codes `forceTLS: false`, `enabledTransports: ['ws']`, and patches `Pusher.Runtime.getProtocol` → `'http:'` so pusher-js 8 does not force `wss://` on `https://platform.test`. Result: **`ws://localhost:6001`**. |

**Do not** nginx-proxy `/app/` → Soketi on Clarity. Filament lives at `/app`; that proxy would shadow the panel (Overwatch can use a dedicated wss host / different layout — Clarity cannot copy that `/app/` trick).

Vite reads `VITE_*` at **worker start** — after changing Pusher env, restart the Vite worker and hard-refresh the browser.

### Two Echo clients

| Client | File | Auth |
|--------|------|------|
| Filament / Otto | `resources/js/app.js` → `window.Echo` | session `/broadcasting/auth` |
| Scout PWA | `resources/js/clarity-scout/lib/echo.js` | Sanctum `/api/broadcasting/auth` |

Keep them separate; do not merge.

## Related

- [jobs-and-queues.md](jobs-and-queues.md) — Horizon supervisors (Otto uses `{otto}`)
- [filament-conventions.md](filament-conventions.md) — `/app` panel
- [scout-real-time-tracking.md](scout-real-time-tracking.md) — Scout’s separate Echo path

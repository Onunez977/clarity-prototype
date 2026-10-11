---
type: Pattern
title: Scout PWA & Offline
description: Scout as an installable Progressive Web App — the web manifest and install prompt, the three-tier service worker cache, the byte-stamp update lifecycle, and the client-initiated API-cache purge that keeps a driver's PII off a shared field unit.
resource: public/clarity-scout-sw.js
tags: [scout, pwa, service-worker, offline, security, cache]
timestamp: 2026-07-06T00:00:00Z
---

# Scout PWA & Offline

The [Scout](scout.md) driver app is an **installable PWA**: a driver can add it to their home screen and it keeps working through spotty coverage. This page documents that layer — the manifest, the service worker, the update lifecycle, and the security-critical cache purge. It is the offline companion to [Scout frontend](scout-frontend.md) (the Vue app) and [real-time tracking](scout-real-time-tracking.md) (the data pipeline). **Only the driver shell registers the service worker** — the admin Bird's Eye blade does not (admins don't need offline).

## Manifest & install

`public/manifest.json`: `display: standalone`, `start_url`/`scope` = `/scout`, `theme_color` `#001166`, `background_color` `#f9fafb`, portrait, 192/512 icons (the 512 is `any maskable`). The driver blade (`resources/views/clarity-scout.blade.php`) links it and adds the iOS `apple-mobile-web-app-*` / `apple-touch-icon` metas plus a `viewport-fit=cover`, non-zoomable viewport.

`composables/useInstallPrompt.js` captures the `beforeinstallprompt` event, stashes it, and exposes `canInstall` only when the app **isn't** already standalone and the user hasn't dismissed it (`localStorage['scanner_install_dismissed']`). `promptInstall()` fires the deferred prompt; `appinstalled` and dismissal both clear the offer.

## Service worker — three cache tiers

`public/clarity-scout-sw.js` (registered from the driver blade: `navigator.serviceWorker.register('/clarity-scout-sw.js')`). All three caches are keyed by `APP_VERSION` so a new build gets fresh caches:

| Tier | Cache | Strategy | Contents |
|------|-------|----------|----------|
| App shell | `clarity-scout-shell-{v}` | **network-first** | `/scout`, `/manifest.json`, the two icons (precached on `install`) |
| Build assets | `clarity-scout-assets-{v}` | **cache-first** | `/build/assets/**` (immutable, Vite-hashed) |
| API data | `clarity-scout-api-{v}` | **network-first** | `GET /api/scout/**` responses, trimmed to `API_MAX_ENTRIES = 50` (oldest evicted) |

`fetch` routing: navigations → network-first shell (offline falls back to the cached `/scout` shell); `/build/assets/` → cache-first; `GET /api/scout/` → network-first API; **everything else is network-only** (never cached — includes all `POST`s and non-Scout requests). When both network and cache miss, the SW returns a `503` with an offline JSON body rather than a broken page.

## Update lifecycle

Standalone PWAs don't re-check the SW on navigation, so updates are pushed two ways:

1. **Byte-stamp on deploy.** `scripts/version-sw.mjs` runs after `vite build` and rewrites `const APP_VERSION = '<timestamp>'` in the SW file, so it changes byte-for-byte every deploy and the browser's update check fires. (This is separate from the committed built assets — see the platform build convention.)
2. **In-app nudge.** On `activate` the new SW purges any cache not in `VALID_CACHES`, claims clients, and `postMessage({type:'SW_UPDATED'})` to every open window. `composables/useServiceWorkerUpdate.js` listens for that message to raise `updateAvailable` (offering a reload) and, when standalone, also polls `registration.update()` every **60 s** so an update is found within a minute even without a navigation.

## PII purge on session transitions (security)

Scout runs on **shared field units** — one tablet, many drivers — so a driver's cached API responses (`/me`, `recent-hits`, `map/hits`, `trips`, …) must not survive sign-out or reach the next user (audit finding F2). The SW listens for a `CLEAR_API_CACHE` message and deletes the entire `clarity-scout-api-{v}` cache. `lib/fetch.js#clearApiCache()` sends it belt-and-suspenders — `postMessage` to the controller **and** a direct `caches.delete()` of every `clarity-scout-api-*` key (covering an inactive or uninstalled SW). It fires on:

- **Logout** (`auth.logout()` → clears tokens, `clearApiCache()`, `disconnectEcho()`).
- **401 (driver) / 403 (admin)** via `handleUnauthorized()` — access lost mid-session.
- **Admin force-login** (`auth.forceAuthenticate()`) — purges *before* hydrating the impersonated driver, so the previous occupant's cache can't leak.

**Nuance vs. the raw "SW caches PII" concern:** the purge is *client-initiated*, so cached API PII is only cleared when one of those triggers runs in the page. Between a driver's last online fetch and the next purge trigger it does sit at rest in the Cache Storage API (the shell/assets caches hold no PII). The force-login purge is what protects the shared-unit hand-off; a hard power-off with no sign-out leaves the last session's API cache until the next launch clears it.

## Offline resilience (client)

- **Detection:** `useConnectionStatus` combines `navigator.onLine` + the Echo/Pusher state with a 5 s grace window; `OfflineBanner.vue` shows while offline in Live mode. See [real-time tracking](scout-real-time-tracking.md).
- **Instant repaint:** the last kit position is cached in `localStorage` (`clarity-scout-kit-position:{userId}`, 24h TTL) so the map paints a dot immediately on reload, even before the first poll returns. See [Scout frontend](scout-frontend.md).
- **Data:** the API tier serves the last-seen response for a repeated `GET` while offline; uncached endpoints return the 503 offline body.

## Where it lives in code

- Service worker: `public/clarity-scout-sw.js`; manifest/icons: `public/manifest.json`, `public/icons/`
- Registration & metas: `resources/views/clarity-scout.blade.php` (driver only)
- Version stamp: `scripts/version-sw.mjs` (post-`vite build`)
- Composables: `resources/js/clarity-scout/composables/useInstallPrompt.js`, `useServiceWorkerUpdate.js`
- Cache purge / auth: `resources/js/clarity-scout/lib/fetch.js` (`clearApiCache`, `handleUnauthorized`), `stores/auth.js`

## See also

- [Scout frontend](scout-frontend.md) — the Vue app the SW serves; local persistence keys
- [Scout real-time tracking](scout-real-time-tracking.md) — offline detection, the polled endpoints the API cache holds
- [Scout](scout.md) — feature hub, the three surfaces (only the driver PWA is offline-capable)
- [Scout user assignment](scout-user-assignment.md) — force-login / impersonation, which triggers the cache purge

/**
 * Clarity Scout Service Worker
 *
 * Three cache tiers:
 * 1. App Shell (network-first) — HTML, manifest, icons; fresh when online, cached fallback offline
 * 2. Build Assets (cache-first) — Vite-hashed JS/CSS (immutable filenames)
 * 3. API Data (network-first) — last responses for offline use
 *
 * APP_VERSION is replaced at build time by scripts/version-sw.mjs
 */

const APP_VERSION = '1791661984519';

const SHELL_CACHE  = `clarity-scout-shell-${APP_VERSION}`;
const ASSETS_CACHE = `clarity-scout-assets-${APP_VERSION}`;
const API_CACHE    = `clarity-scout-api-${APP_VERSION}`;

const VALID_CACHES = [SHELL_CACHE, ASSETS_CACHE, API_CACHE];

const SHELL_URLS = [
    '/scout',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

const API_MAX_ENTRIES = 50;
const SHELL_MAX_ENTRIES = 20;

// --- Install: precache app shell, skip waiting ---
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_URLS))
    );
    self.skipWaiting();
});

// --- Activate: purge old caches, claim clients, notify ---
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) =>
                Promise.all(
                    keys.filter((k) => !VALID_CACHES.includes(k))
                        .map((k) => caches.delete(k))
                )
            )
            .then(() => self.clients.claim())
            .then(() => self.clients.matchAll({ type: 'window' }))
            .then((clients) => {
                clients.forEach((client) => {
                    client.postMessage({ type: 'SW_UPDATED', version: APP_VERSION });
                });
            })
    );
});

// --- Message: purge the authenticated API cache on session transitions ---
// The page posts CLEAR_API_CACHE on logout / 401 / login so one driver's cached
// PII (e.g. /me, recent-hits, map/hits, trips) can't be served to the next user
// on a shared field unit, or sit at rest past sign-out (F2).
self.addEventListener('message', (event) => {
    if (event.data?.type === 'CLEAR_API_CACHE') {
        event.waitUntil(caches.delete(API_CACHE));
    }
});

// --- Fetch: route requests to appropriate strategy ---
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // 1. Driver PWA shell navigations → network-first (fresh HTML when online,
    //    cached fallback offline). Scoped to /scout and /scout/* EXCLUDING
    //    /scout/org-map, so authenticated Filament/admin HTML on other routes is
    //    never cached and the Scout shell is never served for non-Scout routes
    //    offline (H2). org-map lives under /scout/ so the explicit carve-out is
    //    required — a naive startsWith('/scout/') would re-include it.
    //
    //    force-login is carved out for a second reason: its HTML embeds a live
    //    driver bearer token, which must never be written to disk here (the
    //    CLEAR_API_CACHE purge below only reaches API_CACHE), and a replay of
    //    the cached copy would boot the PWA on an already-revoked token.
    if (
        event.request.mode === 'navigate' &&
        url.origin === self.location.origin &&
        (url.pathname === '/scout' ||
            (url.pathname.startsWith('/scout/') &&
                !url.pathname.startsWith('/scout/org-map') &&
                !url.pathname.startsWith('/scout/force-login')))
    ) {
        event.respondWith(networkFirst(event.request, SHELL_CACHE));
        return;
    }

    // 2. Build assets → cache-first (immutable, Vite-hashed)
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(cacheFirst(event.request, ASSETS_CACHE));
        return;
    }

    // 3. API GET requests → network-first with cache fallback
    if (url.pathname.startsWith('/api/scout/') && event.request.method === 'GET') {
        event.respondWith(networkFirst(event.request, API_CACHE));
        return;
    }

    // 4. Everything else → network only
});

// --- Strategies ---

async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
    }
}

async function networkFirst(request, cacheName) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
            if (cacheName === API_CACHE) {
                trimCache(cacheName, API_MAX_ENTRIES);
            } else if (cacheName === SHELL_CACHE) {
                trimCache(cacheName, SHELL_MAX_ENTRIES);
            }
        }
        return response;
    } catch {
        const cached = await caches.match(request);
        if (cached) return cached;

        // For navigation, fall back to the cached app shell
        if (request.mode === 'navigate') {
            const shell = await caches.match('/scout');
            if (shell) return shell;
        }

        return new Response(
            JSON.stringify({ message: 'You are offline and no cached data is available.' }),
            { status: 503, headers: { 'Content-Type': 'application/json' } }
        );
    }
}

// --- Cache maintenance ---

async function trimCache(cacheName, maxEntries) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    if (keys.length > maxEntries) {
        const toDelete = keys.slice(0, keys.length - maxEntries);
        await Promise.all(toDelete.map((key) => cache.delete(key)));
    }
}

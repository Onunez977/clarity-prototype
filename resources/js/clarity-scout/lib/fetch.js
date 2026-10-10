import { disconnectEcho } from './echo';
import { selectedKitIds, followedKitId } from './adminScope';
import { clearCredentials, getCredential } from './scoutCredentials';

// Bird's Eye admin mode (DEV-2116): the same Scout stores talk to the admin
// org-wide endpoints over the web session instead of the driver Sanctum API.
// Flag is set once by the blade; default false keeps the driver path identical.
const ADMIN_MODE = typeof window !== 'undefined' && window.__CLARITY_SCOUT_CONFIG__?.birdsEye === true;

const BASE_URL = ADMIN_MODE ? '/scout/org-map/api' : '/api/scout';

export function authHeaders() {
    const headers = {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
    };
    if (ADMIN_MODE) {
        // Session-cookie authed (sent automatically for same-origin); CSRF for writes.
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrf) {
            headers['X-CSRF-TOKEN'] = csrf;
        }
        return headers;
    }
    const bearer = getCredential('scanner_token');
    if (bearer) {
        headers['Authorization'] = `Bearer ${bearer}`;
    }
    return headers;
}

// Purge the service worker's authenticated API cache (PII at rest / cross-user
// leakage on shared units, F2). Best-effort: postMessage survives the imminent
// page navigation; the direct caches.delete covers an inactive/uninstalled SW.
export async function clearApiCache() {
    try {
        navigator.serviceWorker?.controller?.postMessage({ type: 'CLEAR_API_CACHE' });
        if (typeof caches !== 'undefined') {
            const keys = await caches.keys();
            await Promise.all(
                keys.filter((key) => key.startsWith('clarity-scout-api-')).map((key) => caches.delete(key))
            );
        }
    } catch {
        // Never block the auth flow on cache cleanup.
    }
}

const SIGNED_OUT_REASON_KEY = 'scout_signed_out_reason';

// One bounce per page life: every store polls independently, so a revoked token
// produces a burst of 401s and only the first should own the redirect.
let bounceStarted = false;

export function handleUnauthorized(response) {
    if (!(response.status === 401 || (ADMIN_MODE && response.status === 403))) {
        return;
    }

    if (bounceStarted) {
        return;
    }
    bounceStarted = true;

    if (ADMIN_MODE) {
        // Session expired / access revoked — back to the admin panel login.
        window.location.href = '/app/login';
        return;
    }

    // Record WHY first. This redirect is a full navigation, so without it the
    // toast paints for one frame and the tab is gone — an expired token, a
    // revoked force-login and lost portal access all look identical.
    recordSignedOutReason(response).then(() => {
        clearCredentials();
        clearApiCache();
        disconnectEcho();
        window.location.href = '/scout/login';
    });
}

async function recordSignedOutReason(response) {
    let message = '';

    try {
        const body = await response.clone().json();
        message = typeof body?.message === 'string' ? body.message : '';
    } catch {
        // Non-JSON body (or one already read) — status + path still identify it.
    }

    try {
        sessionStorage.setItem(
            SIGNED_OUT_REASON_KEY,
            JSON.stringify({
                status: response.status,
                path: new URL(response.url, window.location.origin).pathname,
                message,
                at: new Date().toISOString(),
            })
        );
    } catch {
        // Blocked storage — fall through to the plain redirect.
    }
}

/** Read-and-clear, so a reason is shown once and never sticks to a later visit. */
export function takeSignedOutReason() {
    try {
        const raw = sessionStorage.getItem(SIGNED_OUT_REASON_KEY);
        sessionStorage.removeItem(SIGNED_OUT_REASON_KEY);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

export function buildUrl(path, params = null) {
    const url = new URL(BASE_URL + path, window.location.origin);
    // In admin mode, auto-scope every request to the driver-selector's choice so
    // the existing store fetches narrow without per-action plumbing (null = all).
    // Exception: the stats box scopes to the followed/"highlighted" driver when
    // one is set, so the tiles show just that driver; otherwise the selection.
    if (ADMIN_MODE && !path.includes('/map/heatmap/') && !path.includes('/settings/')) {
        // Heatmap layers are intentionally org-wide and selector-independent
        // (order density is address-based, with no kit dimension), so don't append
        // kit_ids for them — the server ignores it and it only dirties the cache
        // key (F11). Every other admin request scopes to the driver selector.
        const followScoped = followedKitId.value != null
            && (path.includes('/dashboard/stats') || path.includes('/dashboard/recent-hits'));
        const scope = followScoped ? [followedKitId.value] : selectedKitIds.value;
        // DEV-2374: historical scan-path chunking passes an explicit kit_ids batch —
        // don't overwrite it with the full selection.
        if (scope && scope.length) {
            const explicit = params?.kit_ids;
            if (!Array.isArray(explicit) || explicit.length === 0) {
                params = { ...(params ?? {}), kit_ids: scope };
            }
        }
    }
    if (params) {
        for (const [key, value] of Object.entries(params)) {
            if (value === undefined || value === null) {
                continue;
            }
            // Emit array values as `key[]=a&key[]=b` so Laravel parses them
            // back into an array rather than a single comma-joined string.
            if (Array.isArray(value)) {
                for (const item of value) {
                    if (item !== undefined && item !== null) {
                        url.searchParams.append(`${key}[]`, item);
                    }
                }
            } else if (typeof value === 'object') {
                // Emit plain objects as `key[k]=v` so Laravel parses them back
                // into a keyed array (e.g. the per-driver `sinces` cursor map).
                for (const [k, v] of Object.entries(value)) {
                    if (v !== undefined && v !== null) {
                        url.searchParams.set(`${key}[${k}]`, v);
                    }
                }
            } else {
                url.searchParams.set(key, value);
            }
        }
    }
    return url.toString();
}

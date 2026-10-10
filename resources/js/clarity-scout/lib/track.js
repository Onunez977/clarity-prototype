import { authHeaders } from './fetch.js';
import { getCredential } from './scoutCredentials.js';

// Fire-and-forget client analytics for the driver PWA (DEV-2130). Callers must
// NOT await this — a tracking failure must never surface to the driver.
//
// Admin/Bird's-Eye mode is intentionally a no-op: those surfaces rely on the
// server-side track() calls in the org-map controllers, so emitting here would
// double-count and there's no /scout/org-map/api/track endpoint anyway.
const ADMIN_MODE = typeof window !== 'undefined' && window.__CLARITY_SCOUT_CONFIG__?.birdsEye === true;

export function scoutTrack(feature, page = null, params = {}) {
    if (ADMIN_MODE) {
        return;
    }
    if (!getCredential('scanner_token')) {
        return;
    }

    fetch('/api/scout/track', {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify({ feature, page, params }),
    }).catch(() => {});
}

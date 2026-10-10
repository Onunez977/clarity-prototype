/**
 * Scout credential storage (DEV-2596).
 *
 * Normal driver login keeps using localStorage so a PWA relaunch can rehydrate.
 * Force-login marks the tab as tab-scoped (sessionStorage marker) so each open
 * Scout tab holds its own bearer — opening driver B must not hijack tab A's REST.
 *
 * Deliberately a marker check, not a read-through fallback: a force-login tab
 * must never silently adopt the driver's own localStorage token.
 *
 * clearCredentials() only clears the CURRENT scope's store. A force-login tab
 * must never delete the driver's live localStorage bearer (AC5 coexist).
 */

const SCOPE_MARKER_KEY = 'scout_credential_scope';
const SCOPE_TAB = 'tab';

const CREDENTIAL_KEYS = [
    'scanner_token',
    'scanner_user',
    'scanner_kit',
    'scanner_is_impersonating',
];

/**
 * @returns {boolean} true when the tab is now tab-scoped
 */
export function markTabScoped() {
    try {
        sessionStorage.setItem(SCOPE_MARKER_KEY, SCOPE_TAB);
        return sessionStorage.getItem(SCOPE_MARKER_KEY) === SCOPE_TAB;
    } catch {
        return false;
    }
}

export function clearTabScopeMarker() {
    try {
        sessionStorage.removeItem(SCOPE_MARKER_KEY);
    } catch {
        // Ignore.
    }
}

export function isTabScoped() {
    try {
        return sessionStorage.getItem(SCOPE_MARKER_KEY) === SCOPE_TAB;
    } catch {
        return false;
    }
}

export function resolveStorage() {
    if (typeof window === 'undefined') {
        return null;
    }

    return isTabScoped() ? sessionStorage : localStorage;
}

export function getCredential(key) {
    const store = resolveStorage();
    if (!store) {
        return null;
    }

    try {
        return store.getItem(key);
    } catch {
        return null;
    }
}

export function setCredential(key, value) {
    const store = resolveStorage();
    if (!store) {
        return;
    }

    try {
        store.setItem(key, value);
    } catch {
        // Ignore quota / private-mode failures.
    }
}

export function removeCredential(key) {
    const store = resolveStorage();
    if (!store) {
        return;
    }

    try {
        store.removeItem(key);
    } catch {
        // Ignore.
    }
}

/**
 * Clear credentials for THIS tab's scope only.
 * Tab-scoped (force-login): wipe sessionStorage credentials + marker.
 * Do NOT touch localStorage — that holds the real driver's coexist session.
 * Normal driver: wipe localStorage credentials.
 */
export function clearCredentials() {
    const tabScoped = isTabScoped();
    const store = tabScoped ? sessionStorage : localStorage;

    for (const key of CREDENTIAL_KEYS) {
        try {
            store.removeItem(key);
        } catch {
            // Ignore.
        }
    }

    if (tabScoped) {
        clearTabScopeMarker();
    }
}

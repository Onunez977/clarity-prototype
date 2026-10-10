import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { authHeaders, buildUrl, clearApiCache, handleUnauthorized } from '../lib/fetch';
import { connectEcho, disconnectEcho } from '../lib/echo';
import {
    clearCredentials,
    getCredential,
    markTabScoped,
    removeCredential,
    setCredential,
} from '../lib/scoutCredentials';
import { useMapStore } from './map';

const DEFAULT_MAP_LAYERS = ['hits', 'hotlist', 'staging', 'teammates'];
const ENABLED_MAP_LAYERS_KEY = 'scanner_enabled_map_layers';

function parseEnabledMapLayers() {
    try {
        const raw = JSON.parse(localStorage.getItem(ENABLED_MAP_LAYERS_KEY) || 'null');
        if (Array.isArray(raw)) {
            return raw.filter(Boolean);
        }
    } catch {
        localStorage.removeItem(ENABLED_MAP_LAYERS_KEY);
    }

    if (localStorage.getItem('scanner_teammate_overlay_enabled') === '1') {
        return [...DEFAULT_MAP_LAYERS];
    }

    return ['hits', 'hotlist', 'staging'];
}

export const useAuthStore = defineStore('auth', () => {
    function safeParse(key) {
        try {
            return JSON.parse(getCredential(key) || 'null');
        } catch {
            removeCredential(key);
            return null;
        }
    }

    const token = ref(getCredential('scanner_token') || null);
    const user = ref(safeParse('scanner_user'));
    const kit = ref(safeParse('scanner_kit'));
    const isImpersonating = ref(getCredential('scanner_is_impersonating') === '1');
    const tripsEnabled = ref(localStorage.getItem('scanner_trips_enabled') === '1');
    const enabledMapLayers = ref(parseEnabledMapLayers());
    const teammateOverlayEnabled = computed(() => enabledMapLayers.value.includes('teammates'));

    // Bird's Eye admin mode (DEV-2116): the org admin views all drivers over the
    // web session — no driver Sanctum token, no Echo. Default false, so every
    // driver code path below is unchanged.
    const adminMode = ref(false);

    const isAuthenticated = computed(() => !!token.value || adminMode.value);
    const kitId = computed(() => kit.value?.id || null);
    const orgId = computed(() => user.value?.org_id || null);

    function persistImpersonating(value) {
        isImpersonating.value = !!value;
        if (isImpersonating.value) {
            setCredential('scanner_is_impersonating', '1');
        } else {
            removeCredential('scanner_is_impersonating');
        }
    }

    function persistTripsEnabled(value) {
        tripsEnabled.value = !!value;
        if (tripsEnabled.value) {
            localStorage.setItem('scanner_trips_enabled', '1');
        } else {
            localStorage.removeItem('scanner_trips_enabled');
        }
    }

    function persistEnabledMapLayers(layers) {
        const list = Array.isArray(layers) ? layers.filter(Boolean) : [];
        enabledMapLayers.value = list;
        localStorage.setItem(ENABLED_MAP_LAYERS_KEY, JSON.stringify(list));
        if (list.includes('teammates')) {
            localStorage.setItem('scanner_teammate_overlay_enabled', '1');
        } else {
            localStorage.removeItem('scanner_teammate_overlay_enabled');
        }
        useMapStore().pruneHeatmapsTo(list);
    }

    // Ignore a missing/malformed payload so a stale /me cache can't wipe the
    // last known availability (DEV-2199). Logout still passes [] explicitly.
    function applyEnabledMapLayers(layers) {
        if (!Array.isArray(layers)) {
            return;
        }
        persistEnabledMapLayers(layers);
    }

    async function login(email, password) {
        const response = await fetch(buildUrl('/login'), {
            method: 'POST',
            headers: authHeaders(),
            body: JSON.stringify({ email, password }),
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({}));
            throw { response: { data: error, status: response.status } };
        }

        const data = await response.json();

        // Drop any prior user's cached API responses before this session's data
        // lands (shared field units, F2).
        await clearApiCache();

        token.value = data.token;
        user.value = data.user;
        kit.value = data.kit;
        persistImpersonating(data.is_impersonating);
        persistTripsEnabled(data.trips_enabled);
        applyEnabledMapLayers(data.enabled_map_layers);

        setCredential('scanner_token', data.token);
        setCredential('scanner_user', JSON.stringify(data.user));
        setCredential('scanner_kit', JSON.stringify(data.kit));

        connectEcho(data.token);

        return data;
    }

    async function forgotPassword(email) {
        const response = await fetch(buildUrl('/forgot-password'), {
            method: 'POST',
            headers: authHeaders(),
            body: JSON.stringify({ email }),
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({}));
            throw { response: { data: error, status: response.status } };
        }

        return response.json();
    }

    async function logout() {
        try {
            await fetch(buildUrl('/logout'), {
                method: 'POST',
                headers: authHeaders(),
            });
        } catch {
            // Ignore errors on logout (token may already be invalid)
        }

        token.value = null;
        user.value = null;
        kit.value = null;
        persistImpersonating(false);
        persistTripsEnabled(false);
        persistEnabledMapLayers([]);
        localStorage.removeItem(ENABLED_MAP_LAYERS_KEY);
        clearCredentials();

        // Purge cached API PII so it can't be read at rest or served to the next
        // user on a shared unit (F2).
        await clearApiCache();

        disconnectEcho();

        // Hard reload to tear down all in-memory Pinia stores and module
        // singletons — a router.push alone leaves one driver's state resident for
        // the next user on a shared field unit (H3). Driver-only: in Bird's Eye
        // admin mode the admin caller performs its own redirect, so don't force
        // the driver login path here.
        if (!adminMode.value) {
            window.location.href = '/scout/login';
        }
    }

    async function fetchUser() {
        // Bird's Eye talks to /scout/org-map/api, which has no /me.
        if (adminMode.value) {
            return null;
        }

        const response = await fetch(buildUrl('/me'), {
            headers: authHeaders(),
        });

        if (!response.ok) {
            // Access revoked mid-session (middleware now 401s) — recover on boot
            // by clearing the stale token and routing back to login (F4).
            handleUnauthorized(response);
            throw new Error('Failed to fetch user');
        }

        const data = await response.json();

        user.value = data.user;
        kit.value = data.kit;
        persistImpersonating(data.is_impersonating);
        persistTripsEnabled(data.trips_enabled);
        applyEnabledMapLayers(data.enabled_map_layers);

        setCredential('scanner_user', JSON.stringify(data.user));
        setCredential('scanner_kit', JSON.stringify(data.kit));

        return data;
    }

    function forceAuthenticate(data) {
        // Staff force-login establishes a different driver's session — purge any
        // prior cached API PII first (F2).
        clearApiCache();

        // Tab-scope BEFORE the first credential write so this tab never shares
        // its bearer with other open Scout tabs (DEV-2596 H2). Fail closed if
        // sessionStorage is unavailable — do not write a gate-bypass token into
        // the driver's shared localStorage.
        if (!markTabScoped()) {
            try {
                sessionStorage.setItem(
                    'scout_signed_out_reason',
                    JSON.stringify({
                        status: 0,
                        path: window.location.pathname,
                        message: 'This browser blocked tab-scoped storage. Re-open Login to Scout in a normal window.',
                        at: new Date().toISOString(),
                    }),
                );
            } catch {
                // Ignore.
            }
            window.location.href = '/scout/login';
            return;
        }

        token.value = data.token;
        user.value = data.user;
        kit.value = data.kit;
        persistImpersonating(data.is_impersonating);
        // Preference keys stay in localStorage for drivers; skip rewriting them
        // from a force-login tab so we don't clobber the driver's map layers.
        isImpersonating.value = !!data.is_impersonating;
        tripsEnabled.value = !!data.trips_enabled;
        if (Array.isArray(data.enabled_map_layers)) {
            enabledMapLayers.value = data.enabled_map_layers.filter(Boolean);
        }

        setCredential('scanner_token', data.token);
        setCredential('scanner_user', JSON.stringify(data.user));
        setCredential('scanner_kit', JSON.stringify(data.kit));
        setCredential('scanner_is_impersonating', data.is_impersonating ? '1' : '0');

        connectEcho(data.token);
    }

    function enterAdminMode(data = {}) {
        adminMode.value = true;
        user.value = data.user ?? null;
        tripsEnabled.value = false;
        enabledMapLayers.value = [];
        useMapStore().fetchDriverMapLayers();
    }

    return { token, user, kit, isImpersonating, tripsEnabled, teammateOverlayEnabled, enabledMapLayers, adminMode, isAuthenticated, kitId, orgId, login, forgotPassword, logout, fetchUser, forceAuthenticate, enterAdminMode };
});

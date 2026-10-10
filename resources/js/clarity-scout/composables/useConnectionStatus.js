import { ref, watch } from 'vue';
import { echoState } from '../lib/echo';

/**
 * Derives a driver-facing "offline" signal from BOTH the realtime socket
 * (echoState) and the browser's own connectivity (navigator.onLine + the
 * window online/offline events). The latter matters because a real network
 * drop (dead zone, Wi-Fi off) is silent to pusher-js until its heartbeat
 * times out (tens of seconds), so echoState alone keeps reporting "connected"
 * long after the device is offline. The window 'offline' event fires
 * immediately, so we react to it directly.
 *
 * A sustained drop (offline-ish for longer than the grace window) flips
 * `isOffline` true and records `offlineSince` (the last time we were
 * connected). Recovery clears it. Brief blips inside the grace window never
 * surface. Module-singleton: listeners install once (guarded) on the first
 * caller — call it from a long-lived root (App.vue) so it lives for the session.
 */
const GRACE_MS = 5000;
const OFFLINE_STATES = ['disconnected', 'unavailable', 'failed'];

const isOffline = ref(false);
const offlineSince = ref(null);

let lastConnectedAt = null;
let graceTimer = null;
let installed = false;

function clearGraceTimer() {
    if (graceTimer !== null) {
        clearTimeout(graceTimer);
        graceTimer = null;
    }
}

function browserOffline() {
    return typeof navigator !== 'undefined' && navigator.onLine === false;
}

function offlineish() {
    return browserOffline() || OFFLINE_STATES.includes(echoState.value);
}

/**
 * Re-evaluate the offline signal. Called on every echoState change and on the
 * window online/offline events, so either source can trip or clear it.
 */
function evaluate() {
    if (echoState.value === 'connected') {
        lastConnectedAt = Date.now();
    }

    if (offlineish()) {
        if (graceTimer === null && !isOffline.value) {
            graceTimer = setTimeout(() => {
                graceTimer = null;
                isOffline.value = true;
                offlineSince.value = lastConnectedAt ?? Date.now();
            }, GRACE_MS);
        }
        return;
    }

    clearGraceTimer();
    isOffline.value = false;
    offlineSince.value = null;
}

function install() {
    if (installed) {
        return;
    }
    installed = true;

    watch(echoState, evaluate, { immediate: true });

    if (typeof window !== 'undefined') {
        window.addEventListener('online', evaluate);
        window.addEventListener('offline', evaluate);
    }
}

export function useConnectionStatus() {
    install();
    return { isOffline, offlineSince };
}

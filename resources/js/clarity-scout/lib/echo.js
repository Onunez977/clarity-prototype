import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref } from 'vue';

let echo = null;

/**
 * Reactive Echo/Pusher connection state. Components can import and watch
 * this to surface a debug indicator in the UI. Possible values follow the
 * Pusher.js Connection states: 'initialized', 'connecting', 'connected',
 * 'unavailable', 'failed', 'disconnected'. Starts as 'disconnected'.
 */
export const echoState = ref('disconnected');

export function connectEcho(token) {
    if (!token) return null;

    if (echo) {
        disconnectEcho();
    }

    echoState.value = 'connecting';

    // Local lerd Soketi: ws://localhost:6001 (scheme=http). Do not force wss —
    // Soketi's published host port has no TLS.
    const useTLS = (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https';

    echo = new Echo({
        broadcaster: 'pusher',
        Pusher,
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        // pusher-js validates `cluster` even when wsHost is set for a custom
        // server (Reverb/Soketi). The value is effectively ignored when wsHost
        // is non-empty; default to Pusher's `mt1` to satisfy the check.
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
        wsHost: import.meta.env.VITE_PUSHER_HOST || 'wss.insightlpr.io',
        wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? (useTLS ? 443 : 80)),
        wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
        forceTLS: useTLS,
        encrypted: useTLS,
        enabledTransports: useTLS ? ['ws', 'wss'] : ['ws'],
        authEndpoint: '/api/broadcasting/auth',
        auth: {
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
            },
        },
    });

    // Mirror Pusher's connection state into our reactive ref.
    try {
        const pusherConn = echo.connector.pusher.connection;
        echoState.value = pusherConn.state || 'connecting';
        pusherConn.bind('state_change', ({ current }) => {
            echoState.value = current;
        });
    } catch (e) {
        // If the connector shape changes in a future laravel-echo release,
        // don't crash app boot — just leave the indicator in 'connecting'.
    }

    return echo;
}

export function disconnectEcho() {
    if (!echo) return;
    try {
        echo.disconnect();
    } catch (e) {
        // ignore — disconnect on a half-open socket can throw
    }
    echo = null;
    echoState.value = 'disconnected';
}

export function getEcho() {
    return echo;
}

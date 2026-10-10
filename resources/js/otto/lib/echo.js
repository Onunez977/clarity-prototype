/**
 * Session-authed Echo for Otto / Filament (`/broadcasting/auth`).
 *
 * Reuse `window.Echo` when Filament `app.js` already created it.
 * On Org Map (or any page without that client), create the same session
 * client as `resources/js/app.js`. Do not merge with Scout's Sanctum Echo
 * (`resources/js/clarity-scout/lib/echo.js` → `/api/broadcasting/auth`).
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

function patchSocketId(echo) {
    const echoSocketId = echo.socketId.bind(echo);
    echo.socketId = () => {
        const socketId = echoSocketId();

        return typeof socketId === 'string' && /^\d+\.\d+$/.test(socketId)
            ? socketId
            : undefined;
    };
}

function createSessionEcho() {
    const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
    if (!pusherKey) {
        return null;
    }

    window.Pusher = window.Pusher || Pusher;

    let echo;
    if (import.meta.env.PROD) {
        echo = new Echo({
            broadcaster: 'pusher',
            key: pusherKey,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
            wsHost: import.meta.env.VITE_PUSHER_HOST ?? 'wss.insightlpr.io',
            wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
            wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
            forceTLS: true,
            encrypted: true,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            authEndpoint: '/broadcasting/auth',
        });
    } else {
        // pusher-js 8.x shouldUseTLS() returns true whenever location.protocol
        // is https — it ignores forceTLS:false. Local Soketi on :6001 has no
        // TLS; localhost is a trustworthy origin so mixed ws:// is allowed.
        Pusher.Runtime.getProtocol = () => 'http:';

        echo = new Echo({
            broadcaster: 'pusher',
            key: pusherKey,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
            wsHost: import.meta.env.VITE_PUSHER_HOST ?? 'localhost',
            wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
            wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
            forceTLS: false,
            encrypted: false,
            enabledTransports: ['ws'],
            disableStats: true,
            authEndpoint: '/broadcasting/auth',
        });
    }

    patchSocketId(echo);

    return echo;
}

/**
 * @returns {import('laravel-echo').default|null}
 */
export function getSessionEcho() {
    if (window.Echo) {
        return window.Echo;
    }

    const echo = createSessionEcho();
    if (echo) {
        window.Echo = echo;
    }

    return echo;
}

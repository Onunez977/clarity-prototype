import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { useAuthStore } from './stores/auth';
import { connectEcho } from './lib/echo';
import { getCredential } from './lib/scoutCredentials';

import '../../css/clarity-scout.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);

// Must run BEFORE mount so router guard sees the token on initial navigation
if (window.__CLARITY_SCOUT_FORCE_AUTH__) {
    const forceAuth = window.__CLARITY_SCOUT_FORCE_AUTH__;
    delete window.__CLARITY_SCOUT_FORCE_AUTH__;

    if (!forceAuth?.token || !forceAuth?.user || !forceAuth?.kit) {
        // Malformed force-login payload — do not boot a half-authed PWA (DEV-2596).
        try {
            sessionStorage.setItem(
                'scout_signed_out_reason',
                JSON.stringify({
                    status: 0,
                    path: window.location.pathname,
                    message: 'Force-login payload was incomplete. Re-open Login to Scout from the admin panel.',
                    at: new Date().toISOString(),
                }),
            );
        } catch {
            // Ignore storage failures.
        }
        window.location.href = '/scout/login';
    } else {
        const auth = useAuthStore();
        auth.forceAuthenticate(forceAuth);
    }
} else {
    const existing = getCredential('scanner_token');
    if (existing) {
        connectEcho(existing);
        // Availability flags (map layers, trips) live in localStorage from the
        // last login. Re-read /me so an admin change shows up on refresh, and
        // again when the PWA tab becomes visible (DEV-2199).
        const auth = useAuthStore();
        auth.fetchUser().catch(() => {});
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                auth.fetchUser().catch(() => {});
            }
        });
    }
}

app.mount('#clarity-scout-app');

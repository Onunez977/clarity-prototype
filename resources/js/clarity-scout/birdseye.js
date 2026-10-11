import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { useAuthStore } from './stores/auth';
import { mountOtto } from '../otto/mount.js';

import '../../css/clarity-scout.css';

// Bird's Eye admin entry (DEV-2116). Mounts the EXACT Scout App.vue — same
// MapShell/ScannerMap/MapStatsBar/ScoutControlsWidget/DateRangeControl/Dashboard
// overlay — so it looks and functions identically to Scout. The only differences
// are admin mode (no driver token/Echo; session-authed via lib/fetch's birdsEye
// branch) and the driver selector. `window.__CLARITY_SCOUT_CONFIG__.birdsEye`
// (set by the blade) flips the data layer to the org-wide admin endpoints.
const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);

// Session-authed admin — no driver Sanctum token, no Echo. Must run before mount
// so the router guard sees isAuthenticated on the initial /scout/org-map nav.
const auth = useAuthStore();
auth.enterAdminMode({ user: window.__CLARITY_SCOUT_CONFIG__?.user ?? null });

app.mount('#clarity-scout-app');

const ottoRoot = document.getElementById('otto-root');
if (ottoRoot && window.__OTTO_CONFIG__) {
    mountOtto(ottoRoot, window.__OTTO_CONFIG__);
    window.Otto?.registerPage({
        pageType: 'scout-map',
        description: 'Org Map',
        state: {},
    });
}

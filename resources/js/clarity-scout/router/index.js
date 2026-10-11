import { createRouter, createWebHistory } from 'vue-router';
import Login from '../views/Login.vue';
const ScoutHome = () => import('../views/ScoutHome.vue');
import { useAuthStore } from '../stores/auth';

const routes = [
    {
        path: '/scout/login',
        name: 'login',
        component: Login,
        meta: { requiresAuth: false },
    },
    {
        // The single authenticated page. All screens (dashboard, trips, day,
        // trip, help, profile) are query-param-driven state on top of one map.
        path: '/scout',
        name: 'app',
        component: ScoutHome,
        meta: { requiresAuth: true },
    },

    // --- Back-compat redirects: old multi-route URLs → the /scout query form ---
    { path: '/scout/dashboard', redirect: { name: 'app' } },
    { path: '/scout/trips', redirect: { name: 'app', query: { trips: null } } },
    {
        path: '/scout/trips/:date(\\d{4}-\\d{2}-\\d{2})',
        redirect: (to) => ({ name: 'app', query: { day: to.params.date } }),
    },
    {
        path: '/scout/trips/:date/:tripIndex',
        redirect: (to) => ({ name: 'app', query: { day: to.params.date, trip: to.params.tripIndex } }),
    },
    { path: '/scout/help', redirect: { name: 'app', query: { help: null } } },
    { path: '/scout/profile', redirect: { name: 'app', query: { profile: null } } },
    { path: '/scout/force-login/:kit', redirect: { name: 'app' } },
    // Bird's Eye — the admin org-wide view (DEV-2116) reuses ScoutHome's whole
    // chrome. Must sit BEFORE the catch-all below, which would otherwise bounce
    // it to login. requiresAuth is satisfied by auth.adminMode (no driver token).
    {
        path: '/scout/org-map',
        name: 'org-map',
        component: ScoutHome,
        meta: { requiresAuth: true },
    },
    { path: '/scout/:pathMatch(.*)*', redirect: { name: 'login' } },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to, from, next) => {
    const auth = useAuthStore();

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        next({ name: 'login' });
    } else if (to.name === 'login' && auth.isAuthenticated) {
        next({ name: 'app' });
    } else {
        next();
    }
});

export default router;

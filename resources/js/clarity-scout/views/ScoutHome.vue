<template>
    <div class="fixed inset-0 pointer-events-none">
        <!-- Map overlays (the map itself is the persistent MapShell in App.vue,
             rendered underneath). Exactly one of these shows at a time. -->
        <DashboardOverlay v-if="!tripActive" />
        <DayOverlay v-else-if="dayView" />
        <TripOverlay v-else-if="tripView" />

        <!-- Floating controls widget (replaces the old static header). Rendered here,
             inside the overlay root, so it shares the slide-out panels' stacking
             context — the widget at z-[25] sits above the map, below the left-column
             Live Hits rail (z-[26], DEV-2594) and below the panels (z-30), so an open
             panel correctly covers it on mobile. pointer-events-auto makes it tappable.
             The End Shift trigger lives inside the widget; its confirmation modal
             stays in App.vue at the root. -->
        <div v-if="auth.isAuthenticated" class="pointer-events-auto">
            <ScoutControlsWidget />
        </div>

        <!-- Slide-out panels, driven by the query string. Wrapped so their
             fixed backdrops/contents are interactive despite the pointer-events
             -none root that lets map gestures pass through elsewhere. -->
        <div class="pointer-events-auto">
            <TripsPanel :open="panel === 'trips'" @close="onClosePanel" />
            <HelpPanel :open="panel === 'help'" @close="onClosePanel" />
            <ProfilePanel :open="panel === 'profile'" @close="onClosePanel" />
            <NotificationsPanel :open="panel === 'notifications'" @close="onClosePanel" />
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useMapStore } from '../stores/map';
import { useDashboardStore } from '../stores/dashboard';
import { useTripsStore } from '../stores/trips';
import { usePrefetch } from '../composables/usePrefetch';
import { closePanel } from '../lib/scoutNav';
import { scoutTrack } from '../lib/track';
import { SCOUT_EVENT, SCOUT_PAGE } from '../lib/analyticsEvents';
import ScoutControlsWidget from '../components/ScoutControlsWidget.vue';
import DashboardOverlay from '../components/DashboardOverlay.vue';
import DayOverlay from '../components/DayOverlay.vue';
import TripOverlay from '../components/TripOverlay.vue';
import TripsPanel from '../components/TripsPanel.vue';
import HelpPanel from '../components/HelpPanel.vue';
import ProfilePanel from '../components/ProfilePanel.vue';
import NotificationsPanel from '../components/NotificationsPanel.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const mapStore = useMapStore();
const dashboard = useDashboardStore();
const trips = useTripsStore();
const { scheduleRangePrefetch } = usePrefetch();

const THEME_RED = [255, 102, 102];

// --- Query → view derivation --------------------------------------------
// Trips is hidden behind the CLARITY_SCOUT_TRIPS flag for the initial rollout,
// so every trip-derived view stays off while the flag is disabled — a deep link
// can't re-enter trip mode (reconcile() strips the query below).
const dayView = computed(() => auth.tripsEnabled && route.query.day != null && route.query.trip == null);
const tripView = computed(() => auth.tripsEnabled && route.query.day != null && route.query.trip != null);
const tripActive = computed(() => dayView.value || tripView.value);
const panel = computed(() => {
    // Profile + notifications fetch driver-only endpoints; in Bird's Eye (admin)
    // BASE_URL is /scout/org-map/api, so a deep-linked ?profile / ?notifications
    // would hit the SPA catch-all and throw. Never open them in admin.
    if (!mapStore.adminMode && route.query.profile !== undefined) return 'profile';
    if (route.query.help !== undefined) return 'help';
    if (auth.tripsEnabled && route.query.trips !== undefined) return 'trips';
    if (!mapStore.adminMode && route.query.notifications !== undefined) return 'notifications';
    return null;
});

function onClosePanel() {
    closePanel(router);
}

// Panel open/close analytics (DEV-2130). A query change flips `panel` between a
// panel key and null; emit an open for the entered panel and a close for the
// left one. Back-navigation fires only on a genuine return to the map — NOT when
// drilling from a panel into a day/trip overlay (that's tracked server-side as
// day-detail / trip-detail, so there's no client event for it here).
watch(panel, (next, prev) => {
    if (prev) {
        scoutTrack(SCOUT_EVENT.PANEL_TOGGLE, SCOUT_PAGE.APP, { action: 'close', panel: prev });
    }
    if (next) {
        scoutTrack(SCOUT_EVENT.PANEL_TOGGLE, SCOUT_PAGE.APP, { action: 'open', panel: next });
    } else if (prev && !tripActive.value) {
        scoutTrack(SCOUT_EVENT.BACK_NAVIGATION, SCOUT_PAGE.APP);
    }
});

// --- Query → map state reconciliation -----------------------------------
// Single writer of viewMode + trip data: every URL transition flows through
// here, so back/forward, deep links, and taps all converge without races.
let loadedKey = null;

function applyDayToMap() {
    const d = trips.dayDetail;
    if (!d) return;
    const paths = (d.trips ?? []).map((t, i) => ({ path: t.path, color: THEME_RED, tripIndex: i }));
    mapStore.enterTripMode({ paths, hits: d.hits_detail ?? [] });
}

function applyTripToMap() {
    const t = trips.tripDetail;
    if (!t) return;
    mapStore.enterTripMode({ path: t.path, hits: t.hits_detail ?? [] });
}

async function loadDay(date) {
    const key = `day:${date}`;
    if (loadedKey === key && trips.dayDetail?.date === date) {
        applyDayToMap();
        return;
    }
    loadedKey = key;
    await trips.fetchDayDetail(date);
    if (loadedKey === key) applyDayToMap();
}

async function loadTrip(date, index) {
    const key = `trip:${date}/${index}`;
    if (loadedKey === key && trips.tripDetail) {
        applyTripToMap();
        return;
    }
    loadedKey = key;
    await trips.fetchTripDetail(date, index);
    if (loadedKey === key) applyTripToMap();
}

function reconcile() {
    // Flag off: drop any trip/day/trips deep-link params so a bookmarked link
    // can't re-enter trip mode, then bail out of trip loading entirely.
    if (!auth.tripsEnabled) {
        const { day, trip, trips: _trips, ...rest } = route.query;
        if (day != null || trip != null || _trips !== undefined) {
            router.replace({ query: rest });
        }
        loadedKey = null;
        mapStore.exitTripMode();
        return;
    }

    const date = route.query.day ?? null;
    const trip = route.query.trip != null ? Number(route.query.trip) : null;

    if (date != null && trip != null) {
        loadTrip(date, trip);
    } else if (date != null) {
        loadDay(date);
    } else {
        loadedKey = null;
        mapStore.exitTripMode();
    }
}

watch(() => route.query, reconcile, { immediate: true });

// --- Dashboard data lifecycle (session-scoped, lives here so it isn't
//     refetched every time the dashboard overlay remounts) -----------------
watch(() => dashboard.range, async (newRange) => {
    mapStore.setRange(newRange);
    // DEV-2293: annotate roster before historical scan-paths chunking needs kit ids
    // (RN5 M3 — fetchScanPaths deferred until drivers exist).
    if (mapStore.adminMode) {
        await mapStore.fetchDrivers(newRange);
    }
    // DEV-2185: only fetch imperatively when the settled viewport is at/above the
    // zoom floor and its bbox is known — this avoids a wide/unbounded request and
    // sidesteps the getBounds()-lags-setZoom() race with the zoom-snap. When not
    // eligible (zoomed out), DateRangeControl's zoom-snap brings the camera to the
    // floor and the resulting idle → refreshScanPaths issues the single scoped
    // fetch for this same (already-set) range.
    if (mapStore.mapBounds && mapStore.pathLayersEligible) {
        mapStore.fetchScanPaths(newRange, mapStore.mapBounds);
    }
    // DEV-2016: individual hit logo markers for the picked range (Historical only;
    // selecting a range exits Live, so this is the non-live path).
    mapStore.fetchRangeHits(newRange);
});

onMounted(async () => {
    mapStore.setRange(dashboard.range);
    const loads = [
        dashboard.fetchAll(),
        // Hit markers (DEV-2016): today's hits when the session boots in Live (the
        // default), otherwise the selected historical range.
        mapStore.fetchRangeHits(mapStore.liveMode ? 'today' : dashboard.range),
    ];
    // DEV-2185: on boot the map bounds aren't known yet (no idle has fired), so a
    // fetch here would be unbounded — skip it. The first idle → refreshScanPaths
    // issues the scoped fetch (a no-op in Live, which renders no scan paths).
    if (mapStore.mapBounds && mapStore.pathLayersEligible) {
        loads.push(mapStore.fetchScanPaths(dashboard.range, mapStore.mapBounds));
    }
    await Promise.all(loads);
    scheduleRangePrefetch(dashboard, mapStore);
});
</script>

<template>
    <div class="min-h-screen">
        <!-- Update modal -->
        <div v-if="updateAvailable"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
             style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)">
            <div class="max-w-sm w-[90%] rounded-2xl shadow-2xl overflow-hidden">
                <div class="bg-blue-600 px-6 pt-8 pb-6 text-center text-white">
                    <div class="mx-auto mb-3 w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
                        </svg>
                    </div>
                    <h2 class="text-lg font-bold">Update Available</h2>
                    <p class="text-sm mt-1 opacity-90">A new version of Clarity is ready.</p>
                </div>
                <div class="bg-white px-6 py-5 flex flex-col gap-3">
                    <button @click="reload"
                            class="w-full py-3 rounded-xl bg-blue-600 text-white text-sm font-semibold tracking-wide uppercase">
                        Update Now
                    </button>
                    <button @click="dismiss"
                            class="w-full py-3 rounded-xl bg-gray-100 text-gray-600 text-sm font-medium tracking-wide uppercase">
                        Dismiss
                    </button>
                </div>
            </div>
        </div>

        <!-- The persistent map — mounted once while authenticated, behind everything. -->
        <MapShell v-if="auth.isAuthenticated" />

        <!-- Org Map (admin): when the org is active today but nothing is reporting a
             live position, the map is blank with no explanation. Overlay a neutral
             notice centered on the map so it isn't read as idle drivers (DEV-2197).
             The container stays non-interactive (map draggable underneath); only the
             dismiss ✕ opts back into pointer events so it can be closed (DEV-2292). -->
        <!-- DEV-2311: during the admin Live-mode initial load, show a bare centered
             spinner until the first driver-positions fetch resolves, so the "No kits"
             notice can't flash before positions arrive. -->
        <div v-if="mapStore.adminMode && mapStore.liveMode && !mapStore.positionsLoaded"
             class="pointer-events-none fixed inset-0 z-30 flex items-center justify-center">
            <svg class="animate-spin w-8 h-8 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
        </div>

        <div v-if="noKitsReporting && !noticeDismissed"
             class="pointer-events-none fixed inset-0 z-30 flex items-center justify-center px-4">
            <div class="relative flex flex-col items-center gap-2 max-w-xs rounded-2xl bg-white/95 backdrop-blur-sm shadow-scout-panel border border-scout-edge px-6 py-5 text-center">
                <button type="button"
                        @click="noticeDismissed = true"
                        aria-label="Dismiss"
                        class="pointer-events-auto absolute top-2 right-2 p-1 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100 active:scale-95 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                </svg>
                <p class="text-sm font-semibold text-gray-700">No kits are reporting GPS locations</p>
                <p class="text-xs text-gray-400">Vehicles may still be scanning — live locations aren't available right now.</p>
            </div>
        </div>

        <router-view />

        <Toast />

        <!-- Bottom stack — the offline banner sits just above the stat sheet, both
             bottom-anchored and centered. The old Map/Trips/Help nav lived here;
             this space now carries the date control + live stat row. The My Location
             badge is absolutely positioned off the sheet's right edge, so it can
             appear/disappear without shifting the centered sheet. -->
        <div v-if="bottomClusterVisible"
             class="fixed bottom-8 z-40 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2 pointer-events-none">
            <!-- Dashboard error — COMPACT only (desktop shows it top-right via
                 DashboardOverlay). On mobile it stacks in the bottom cluster, above the
                 controls, so it never collides with the floating top overlays (any of
                 which can expand). -->
            <div v-if="isCompact && !mapStore.adminMode && dashboard.error"
                 class="pointer-events-auto w-full max-w-[min(380px,calc(100vw-2.5rem))]">
                <ErrorState :message="dashboard.error" @retry="dashboard.fetchAll()" />
            </div>

            <!-- Org Map: label whose numbers the stat tiles below are showing. Crimson
                 when narrowed to a driver; neutral grey for the org-wide/subset default
                 so it doesn't shout. Tied to showStatBar so it can't outlive the tiles
                 it describes. -->
            <div v-if="mapStore.adminMode && showStatBar"
                 role="status"
                 aria-live="polite"
                 class="pointer-events-auto flex items-center gap-2 rounded-full text-white text-xs font-semibold px-4 py-1.5 shadow-scout-panel"
                 :class="mapStore.statsScope.state === 'followed' ? 'bg-[#DC143C]' : 'bg-gray-800'">
                <span class="inline-block w-2 h-2 rounded-full bg-white"></span>
                <template v-if="mapStore.statsScope.state === 'followed'">
                    Showing only {{ mapStore.statsScope.driverName }}'s stats
                </template>
                <template v-else-if="mapStore.statsScope.state === 'subset'">
                    Filtered to {{ mapStore.statsScope.selectedCount }} {{ mapStore.statsScope.driverNoun }}
                </template>
                <template v-else>
                    All Drivers
                </template>
            </div>

            <!-- Org Map: no kit is reporting a live position though the org is active
                 today (DEV-2197). Admin has no OfflineBanner, so this pill is the only
                 persistent feed signal; mirrors OfflineBanner's amber style. -->
            <div v-if="noKitsReporting"
                 role="status"
                 aria-live="polite"
                 class="pointer-events-auto inline-flex items-center gap-2 max-w-[92vw] rounded-2xl shadow-scout-panel bg-amber-500/95 text-white px-4 py-2 text-sm font-medium">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7.5-4.108-7.5-11.25a7.5 7.5 0 1115 0c0 7.142-7.5 11.25-7.5 11.25z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                </svg>
                <span>No kits are reporting GPS locations</span>
            </div>

            <!-- Mobile action row — sits just above the stat sheet. On compact the
                 Clarity Scout controls widget is hidden, so its actions relocate here.
                 Order: My Location · Notifications · Help · End Shift. Driver app only;
                 desktop keeps these in the controls widget. -->
            <div v-if="isCompact && !mapStore.adminMode && showStatBar"
                 class="pointer-events-auto flex items-center gap-2.5">
                <button
                    v-if="mapStore.liveMode"
                    type="button"
                    @click="recenter()"
                    :aria-label="hasKitPosition ? 'Recenter to your current location' : 'Find my location'"
                    :title="hasKitPosition ? 'Recenter to your current location' : 'Find my location'"
                    class="w-12 h-12 min-h-[44px] min-w-[44px] flex items-center justify-center rounded-full bg-white shadow-scout-panel border border-scout-edge text-[#FF6666] hover:bg-[#FFF0F0] active:scale-95 transition-colors"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8a4 4 0 100 8 4 4 0 000-8z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v3m0 14v3m10-10h-3M5 12H2" />
                    </svg>
                </button>

                <NotificationBell
                    button-class="w-12 h-12 rounded-full bg-white shadow-scout-panel border border-scout-edge text-gray-600"
                    icon-class="w-6 h-6"
                />

                <button
                    type="button"
                    @click="navHelp"
                    title="Help & glossary"
                    aria-label="Help & glossary"
                    class="w-12 h-12 min-h-[44px] min-w-[44px] flex items-center justify-center rounded-full bg-white shadow-scout-panel border border-scout-edge text-gray-600 active:scale-95 transition"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                </button>

                <button
                    v-if="shift.open.value"
                    type="button"
                    @click="confirmEndShift = true"
                    :disabled="shift.loading.value"
                    title="End your shift"
                    aria-label="End your shift"
                    class="h-12 min-h-[44px] inline-flex items-center gap-2 px-4 rounded-full bg-white shadow-scout-panel border border-scout-edge text-rose-600 font-semibold text-sm active:scale-95 transition disabled:opacity-60 disabled:cursor-wait"
                >
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.25">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.25h13.5v13.5H5.25z" />
                    </svg>
                    End Shift
                </button>
            </div>

            <!-- Offline banner — sits between the action row and the stat sheet (just
                 above the sheet). Admin has no driver Echo connection, so it never shows
                 in Org Map. -->
            <OfflineBanner v-if="!mapStore.adminMode" />

            <!-- DEV-2314: "zoom in to view path history" hint — floats in the bottom
                 cluster just above the stat sheet (over the map), not inside the sheet,
                 so users see it right where the date range filters are. Display-only. -->
            <div v-if="mapStore.showZoomHint" role="status" aria-live="polite"
                 class="pointer-events-none bg-white shadow border-2 border-red-500 text-gray-700 text-sm font-medium px-4 py-2 rounded-full flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6" />
                </svg>
                Zoom in to view path history
            </div>

            <!-- Stat sheet — its outline turns amber while offline in Live mode to
                 match the banner. -->
            <div v-if="showStatBar"
                 class="relative pointer-events-auto bg-white rounded-3xl shadow-scout-panel border transition-colors"
                 :class="offlineStale ? 'border-amber-500' : 'border-scout-edge'">
                <!-- Map-first on small screens: collapses to a compact handle pill
                     (grabber + condensed scans · hits) below `lg`, expands on tap;
                     an explicit choice persists. Driver app only — Org Map / Bird's
                     Eye (admin) keeps the always-expanded bar (DEV-2116, AC8). -->
                <button
                    v-if="!mapStore.adminMode && !barExpanded"
                    type="button"
                    @click="toggleBar"
                    class="flex items-center gap-3 h-12 min-h-[44px] px-5"
                    aria-label="Expand stats"
                    :aria-expanded="false"
                >
                    <span class="w-8 h-1 rounded-full bg-gray-300 flex-shrink-0"></span>
                    <span class="text-sm font-semibold text-gray-700 whitespace-nowrap">
                        {{ condensedScans }} <span class="font-normal text-gray-400">scans</span>
                        <span class="text-gray-300">·</span>
                        {{ condensedHits }} <span class="font-normal text-gray-400">hits</span>
                    </span>
                </button>

                <template v-if="mapStore.adminMode || barExpanded">
                    <!-- Collapse handle — tap to collapse the bar (mirrors the
                         BottomSheet drag handle). Driver only; never in admin. -->
                    <button
                        v-if="!mapStore.adminMode"
                        type="button"
                        @click="toggleBar"
                        class="w-full flex justify-center pt-2.5 pb-0.5 cursor-pointer"
                        aria-label="Collapse stats"
                        :aria-expanded="true"
                    >
                        <span class="w-10 h-1 rounded-full bg-gray-300"></span>
                    </button>
                    <!-- Date range control (DEV-2076) — part of the bottom sheet, above the
                         stat row. Admin keeps the original top padding (no handle above it);
                         driver uses less, since the collapse handle adds the top spacing. -->
                    <div class="px-3 pb-2.5 border-b border-gray-100" :class="mapStore.adminMode ? 'pt-3' : 'pt-1'">
                        <DateRangeControl />
                    </div>
                    <div class="flex items-center h-[72px] px-3" style="min-width: min(380px, calc(100vw - 2rem))">
                        <MapStatsBar class="flex-1" />
                    </div>
                </template>

                <!-- My Location — DESKTOP only (Live mode; hidden in Org Map). Floats
                     off the sheet's right edge. On compact it lives in the mobile
                     action row above the sheet instead. -->
                <div v-if="mapStore.liveMode && !mapStore.adminMode && !isCompact"
                     class="absolute group left-full bottom-0 ml-3">
                    <span class="pointer-events-none absolute bottom-full right-0 mb-2 whitespace-nowrap rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-medium text-white shadow-lg opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100 group-active:opacity-100">
                        {{ hasKitPosition ? 'Recenter to your current location' : 'Find my location' }}
                    </span>
                    <button
                        type="button"
                        @click="recenter()"
                        :aria-label="hasKitPosition ? 'Recenter to your current location' : 'Find my location'"
                        class="flex items-center justify-center rounded-full w-14 h-14 bg-white shadow-scout-panel border border-scout-edge text-[#FF6666] hover:bg-[#FFF0F0] active:scale-95 transition-colors min-h-[44px] min-w-[44px]"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8a4 4 0 100 8 4 4 0 000-8z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v3m0 14v3m10-10h-3M5 12H2" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- End Shift confirmation modal — global so it works from any state. -->
        <div
            v-if="confirmEndShift"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4"
            @click.self="confirmEndShift = false"
        >
            <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-2">End your shift?</h2>
                <p class="text-sm text-slate-600 mb-5">
                    A summary of today's activity (miles, scans, hits, and zones covered) will be emailed to you and your company admins.
                </p>
                <p v-if="shift.error.value" class="text-sm text-rose-600 mb-3">
                    {{ shift.error.value }}
                </p>
                <div class="flex gap-3 justify-end">
                    <button
                        type="button"
                        class="px-4 py-2 rounded-lg text-slate-600 hover:bg-slate-100 font-medium transition"
                        @click="confirmEndShift = false"
                        :disabled="shift.loading.value"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="px-4 py-2 rounded-lg bg-rose-600 text-white font-semibold hover:bg-rose-700 transition disabled:opacity-60 disabled:cursor-wait"
                        @click="handleConfirmEnd"
                        :disabled="shift.loading.value"
                    >
                        {{ shift.loading.value ? 'Ending…' : 'End Shift' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useAuthStore } from './stores/auth';
import { useRoute, useRouter } from 'vue-router';
import { openHelp } from './lib/scoutNav';
import { scoutTrack } from './lib/track';
import { SCOUT_EVENT, SCOUT_PAGE } from './lib/analyticsEvents';
import { useServiceWorkerUpdate } from './composables/useServiceWorkerUpdate';
import { useZoneRealtime } from './composables/useZoneRealtime';
import { useBirdsEyePolling } from './composables/useBirdsEyePolling';
import { useConnectionStatus } from './composables/useConnectionStatus';
import { useShift } from './composables/useShift';
import { useViewport } from './composables/useViewport';
import { useCollapsible } from './composables/useCollapsible';
import { useDashboardStore } from './stores/dashboard';
import { useNotificationsStore } from './stores/notifications';
import { useMapStore } from './stores/map';
import MapShell from './components/MapShell.vue';
import Toast from './components/Toast.vue';
import MapStatsBar from './components/MapStatsBar.vue';
import DateRangeControl from './components/DateRangeControl.vue';
import OfflineBanner from './components/OfflineBanner.vue';
import NotificationBell from './components/NotificationBell.vue';
import ErrorState from './components/ErrorState.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
function navHelp() {
    openHelp(router);
}
function recenter() {
    scoutTrack(SCOUT_EVENT.MAP_RECENTER, SCOUT_PAGE.MAP);
    mapStore.requestKitRecenter();
}
const { updateAvailable, reload, dismiss } = useServiceWorkerUpdate();
const shift = useShift();
const notifications = useNotificationsStore();
const mapStore = useMapStore();
const dashboard = useDashboardStore();

// Bottom stat bar — map-first collapse on small screens. Collapsed it shows a
// condensed scans · hits summary, mirroring MapStatsBar's source (Live mode
// tracks today; Historical shows the picked range).
const { isCompact } = useViewport();
// The bottom sheet opens expanded on every breakpoint (full stats by default on mobile);
// still manually collapsible via the grabber, and the choice persists.
const { expanded: barExpanded, toggle: toggleBar } = useCollapsible('scanner_bottom_bar_expanded', { isCompact, collapseWhenCompact: false });
const statSource = computed(() => (mapStore.liveMode ? (dashboard.statsByRange.today ?? null) : dashboard.stats));
const condensedScans = computed(() => (statSource.value?.scans_today ?? 0).toLocaleString());
const condensedHits = computed(() => ((statSource.value?.direct_hits ?? 0) + (statSource.value?.staging_hits ?? 0)).toLocaleString());

const hasKitPosition = computed(() => mapStore.kitPosition?.lat != null);

// Org Map (admin): the driver roster and the live GPS dots are independent feeds
// (roster = every non-deleted kit; dots = clarity.sc_positions, or a kit_scan_paths
// rescue), so a blank map reads ambiguously — "everyone's parked" vs "nothing is
// reporting a position." When the org is active today (has scans) yet no kit has a
// real GPS fix, surface a neutral notice so the empty map isn't mistaken for idle
// drivers. Counts real GPS only, so a fully-dark org with scan-derived dots still
// gets the notice (DEV-2197, DEV-2253).
const noKitsReporting = computed(() =>
    mapStore.adminMode
    && mapStore.liveMode
    // DEV-2311: gate on the initial positions fetch having resolved, so an
    // empty driverPositions during the mount-order race can't flash the notice.
    && mapStore.positionsLoaded
    && mapStore.drivers.length > 0
    && (mapStore.driverPositions ?? []).filter((p) => p.position_source === 'gps').length === 0
    && (dashboard.statsByRange.today?.scans_today ?? 0) > 0
);

// The admin can dismiss the centered no-GPS notice card. The bottom amber pill stays
// as the persistent feed signal — only the centered overlay is dismissible. Session-
// scoped only: a plain ref resets on the next App mount (page reload), so the card
// stays hidden for the rest of this session even if kits report and go dark again —
// no re-arm on state change (DEV-2292).
const noticeDismissed = ref(false);

useZoneRealtime();
// Bird's Eye admin live-poll (DEV-2116) — self-gates on adminMode; inert for drivers.
useBirdsEyePolling();
const { isOffline } = useConnectionStatus();
// Amber-outline the stat sheet (and show the banner) only while offline in Live mode.
const offlineStale = computed(() => isOffline.value && mapStore.liveMode && !mapStore.adminMode);

// Fetch shift status whenever the user is authenticated. Re-runs on login
// so the End Shift button appears immediately for a returning driver.
watch(
    () => auth.isAuthenticated,
    (authed) => {
        if (authed) {
            if (!mapStore.adminMode) {
                shift.fetchStatus();
                notifications.fetch().catch(() => {});
            }
        } else {
            notifications.reset();
        }
    },
    { immediate: true },
);

// Shared with the floating End Shift trigger that now lives in ScoutHome; the
// confirmation modal stays here at the root so it layers above the stat bar.
const confirmEndShift = shift.confirmEndShift;

async function handleConfirmEnd() {
    try {
        await shift.endShift();
        confirmEndShift.value = false;
    } catch {
        // shift.error is set by the composable; keep modal open so the user sees it.
    }
}

// --- Query-derived view flags (everything is /scout + query now) ---
const hasTrip = computed(() => route.query.day != null);
// A slide-over panel (profile / help / notifications) is open.
const anyPanelOpen = computed(() =>
    route.query.profile !== undefined
    || route.query.help !== undefined
    || route.query.notifications !== undefined
);
// On desktop the bottom cluster stays put while a slide-out is open (the panel is a
// left rail, the centered sheet has room). On compact the panel is full/near-full
// width, so the whole bottom cluster is hidden while a panel is open — the slide-over
// is the top-level surface.
const bottomClusterVisible = computed(() => auth.isAuthenticated && !(isCompact.value && anyPanelOpen.value));
// The stat bar stays put even while a slide-out is open on desktop — only the trip/day
// view, which swaps the map underneath, hides it.
const showStatBar = computed(() => auth.isAuthenticated && !hasTrip.value && !mapStore.selectedHit);
</script>

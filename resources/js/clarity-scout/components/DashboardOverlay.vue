<template>
    <div class="fixed inset-0 pointer-events-none">
        <!-- Error toast — DESKTOP ONLY. Sits top-right, left of the 280px Map Layers
             card. On compact the top row is full of floating widgets (and any one can
             expand), so the error is rendered in App.vue's bottom cluster instead,
             where it stacks cleanly and never collides with the top overlays. -->
        <div
            v-if="dashboard.error && !isCompact"
            class="absolute z-30 flex justify-end pointer-events-none"
            :style="{
                top: 'calc(env(safe-area-inset-top) + 12px)',
                left: '1.25rem',
                right: 'calc(1.25rem + 292px)',
            }"
        >
            <div class="pointer-events-auto w-full max-w-[320px]">
                <ErrorState
                    :message="dashboard.error"
                    @retry="dashboard.fetchAll()"
                />
            </div>
        </div>

        <!-- Floating widgets — top left, below header. The date control moved into
             the bottom sheet (DEV-2076); this column now holds only Live Hits. -->
        <!-- DEV-2594: left column stacks at z-[26] above ScoutControlsWidget
             (z-[25]). Height offset keeps them from overlapping; if the offset
             is ever stale, the Live Hits rows must still win the click rather
             than the controls chrome eating the newest card. -->
        <div class="absolute left-5 z-[26] flex flex-col gap-3 pointer-events-none"
             :style="{ top: (mapStore.adminMode || isCompact)
                 ? 'calc(env(safe-area-inset-top) + 12px)'
                 : 'calc(env(safe-area-inset-top) + var(--scout-controls-h, 132px) + 32px)' }">
            <!-- Org Map (DEV-2116): in admin mode the left side is ONLY the driver
                 selector — the controls widget + Live Hits card are hidden. A
                 hard-coded back link sits above it because the browser back button
                 can't unwind out of the SPA into the Clarity panel. -->
            <a
                v-if="mapStore.adminMode"
                :href="backUrl"
                class="pointer-events-auto self-start inline-flex items-center gap-2 h-11 min-h-[44px] pl-3 pr-4 bg-white/95 backdrop-blur-sm rounded-full shadow-scout-panel border border-scout-edge text-sm font-semibold text-gray-600 hover:text-gray-900 hover:bg-white transition-colors"
            >
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                <span>Back to Clarity</span>
            </a>

            <DriverSelector v-if="mapStore.adminMode" />

            <!-- Live Hits Pending Repossession (DEV-1965). Driver app only. Map-first
                 on small screens: collapses to a compact pill (count badge) below
                 `lg`, expands on tap; an explicit choice persists. -->
            <button
                v-if="!liveHitsExpanded"
                type="button"
                @click="toggleLiveHits"
                class="pointer-events-auto self-start inline-flex items-center gap-2 h-11 min-h-[44px] pl-3 pr-3.5 max-w-[calc(100vw-2.5rem)] bg-white/95 backdrop-blur-sm rounded-full shadow-scout-panel border border-scout-edge"
                aria-label="Show live hits pending repossession"
                :aria-expanded="false"
            >
                <svg class="w-4 h-4 text-[#FF6666] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                </svg>
                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider truncate min-w-0">Live Hits Pending Repossession</span>
                <span
                    v-if="dashboard.loading || mapStore.rangeHitsLoading"
                    class="inline-flex items-center justify-center min-w-5 h-5 flex-shrink-0"
                    aria-label="Loading live hits"
                >
                    <svg class="w-4 h-4 text-[#FF6666] animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                    </svg>
                </span>
                <span
                    v-else-if="dashboard.recentHits.length"
                    class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full bg-[#FF6666] text-white text-xs font-bold leading-none flex-shrink-0"
                >{{ dashboard.recentHits.length }}</span>
            </button>

            <div v-if="liveHitsExpanded" class="pointer-events-auto bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge p-4 w-[min(280px,calc(100vw-2.5rem))]">
                <button
                    type="button"
                    @click="toggleLiveHits"
                    class="w-full flex items-center gap-2 min-h-[44px] text-left"
                    aria-label="Collapse live hits"
                    :aria-expanded="true"
                >
                    <span class="flex-1 text-xs font-bold text-gray-400 uppercase tracking-wider">Live Hits Pending Repossession</span>
                    <svg
                        class="w-5 h-5 text-gray-400 transition-transform duration-200 flex-shrink-0 rotate-180"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <p class="text-xs leading-snug text-gray-400 -mt-1">
                    {{ liveHitsDescription }}
                </p>
                <p
                    v-if="dashboard.refreshing && !dashboard.loading"
                    class="mt-1 text-xs text-gray-400"
                    aria-live="polite"
                >
                    Updating…
                </p>
                <!-- DEV-2597: map markers still cap at MAX_MAP_HITS=100; admin never saw the
                     driver-only hit-key panel, so surface truncation here too. -->
                <p
                    v-if="mapStore.rangeHitsCapped"
                    class="mt-1 text-xs text-amber-700/80"
                    role="status"
                >
                    Map shows the 100 most recent hits for this range.
                </p>
                <div class="flex flex-col mt-2 max-h-[40vh] overflow-y-auto" :class="{ 'opacity-60': dashboard.refreshing }">
                    <p v-if="dashboard.loading || mapStore.rangeHitsLoading" class="text-sm text-gray-400 py-2" aria-live="polite">Loading…</p>
                    <p v-else-if="!dashboard.recentHits.length" class="text-sm text-gray-400 py-2">No live hits pending repossession.</p>
                    <template v-else>
                        <button
                            v-for="hit in dashboard.recentHits"
                            :key="hit.scan_id ?? hit.id"
                            type="button"
                            @click="selectHitFromCard(hit)"
                            class="flex items-center justify-between gap-2 py-2 min-h-[44px] border-b border-gray-100 last:border-0 text-left cursor-pointer"
                        >
                            <div class="flex flex-col min-w-0">
                                <span class="text-sm font-semibold text-gray-900 truncate">{{ hit.plate }}</span>
                                <span class="text-xs text-gray-400">{{ formatHitTime(hit.hit_time) }}</span>
                            </div>
                            <span
                                v-if="hitTypeLabel(hit)"
                                class="flex-shrink-0 text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full"
                                :class="hit.hit_type === 'S' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'"
                            >{{ hitTypeLabel(hit) }}</span>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Hit markers key (DEV-2114, moved here from the map legend) — tappable
                 company-logo hit markers (DEV-2016); capped at the 100 most recent. -->
            <div v-show="!mapStore.adminMode && !isCompact && mapStore.rangeHits && mapStore.rangeHits.length"
                 class="pointer-events-auto bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge p-4 w-[min(280px,calc(100vw-2.5rem))]">
                <div class="flex items-center gap-1.5">
                    <img :src="'/icons/icon-192.png'" alt="" class="w-4 h-4 rounded-sm flex-shrink-0" />
                    <span class="text-xs text-gray-600">Hit — tap for details</span>
                </div>
                <p v-if="mapStore.rangeHitsCapped" class="mt-1 text-xs text-gray-400">
                    Showing the 100 most recent hits.
                </p>
            </div>
        </div>

        <!-- Floating layer filters + legend — top right, below header -->
        <div class="absolute right-5 z-20 flex flex-col gap-3 pointer-events-none items-end"
             style="top: calc(env(safe-area-inset-top) + 12px)">
            <!-- Mobile-only Profile button — top-right next to Map Layers; carries the
                 realtime connection dot. The desktop controls widget (which hosts
                 profile + dot on ≥lg) is hidden on compact. -->
            <button
                v-if="!mapStore.adminMode && isCompact"
                type="button"
                @click="navProfile"
                class="pointer-events-auto relative w-11 h-11 min-h-[44px] min-w-[44px] flex items-center justify-center rounded-full bg-white/95 backdrop-blur-sm shadow-scout-panel border border-scout-edge text-gray-500"
                aria-label="Your profile"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                </svg>
                <span
                    class="absolute -top-0.5 -right-0.5 inline-flex h-3 w-3 rounded-full ring-2 ring-white"
                    :class="echoDotClass"
                    role="status"
                    :title="`Realtime: ${echoState}`"
                    :aria-label="`WebSocket connection: ${echoState}`"
                ></span>
            </button>

            <!-- Map-first on small screens: below `lg` the whole Map Layers card
                 collapses to a layers-icon chip (tap to open the full panel),
                 reusing the same persisted expanded state. -->
            <button
                v-if="isCompact && !layersExpanded"
                type="button"
                @click="toggleLayers"
                class="pointer-events-auto self-end inline-flex items-center justify-center w-11 h-11 min-h-[44px] min-w-[44px] bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge text-gray-500"
                aria-label="Show map layers"
                :aria-expanded="false"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                </svg>
            </button>

            <div v-if="!isCompact || layersExpanded"
                 class="relative z-20 bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge p-4 w-[min(280px,calc(100vw-2.5rem))] pointer-events-auto">
                <button
                    @click="toggleLayers"
                    class="w-full flex items-center gap-2 min-h-[44px] text-left"
                >
                    <span class="flex-1 text-xs font-bold text-gray-400 uppercase tracking-wider">Map Layers</span>
                    <svg
                        class="w-5 h-5 text-gray-400 transition-transform duration-200 flex-shrink-0"
                        :class="{ 'rotate-180': layersExpanded }"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div v-show="layersExpanded" class="flex flex-col gap-2 mt-3">
                    <p v-if="mapStore.adminMode" class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">On this map</p>
                    <LayerToggleTip
                        v-for="layer in heatmapLayers"
                        :key="layer.type"
                        :tip-key="layer.type"
                        v-model:open="openTip"
                        :text="layer.tip"
                    >
                        <button
                            @click="handleToggleHeatmap(layer.type)"
                            class="w-full flex items-center gap-2 px-3 py-3 rounded-xl text-sm font-semibold transition-all min-h-[48px] text-left"
                            :style="layerStyle(layer)"
                        >
                            <span class="w-3 h-3 rounded-full flex-shrink-0"
                                  :style="{ backgroundColor: layer.color }"></span>
                            <span>{{ layer.label }}</span>
                        </button>
                    </LayerToggleTip>

                    <!-- Color basemap toggle (DEV-2060): a display option (not a data
                         layer), so it shares the layer-button shape but carries its
                         own on/off styling. Highlighted = full-color roads; default
                         off = the desaturated monochrome basemap. -->
                    <LayerToggleTip
                        tip-key="colorMap"
                        v-model:open="openTip"
                        text="Switch between the full-color road map and the muted monochrome basemap."
                    >
                        <button
                            @click="mapStore.toggleColorMap()"
                            :aria-pressed="mapStore.colorMapEnabled"
                            class="w-full flex items-center gap-2 px-3 py-3 rounded-xl text-sm font-semibold transition-all min-h-[48px] text-left"
                            :style="colorMapStyle"
                        >
                            <span class="w-3 h-3 rounded-full flex-shrink-0"
                                  :style="{ background: 'linear-gradient(135deg, #ef4444, #eab308, #22c55e, #3b82f6)' }"></span>
                            <span>Color Map</span>
                        </button>
                    </LayerToggleTip>

                    <!-- Direction arrows toggle (DEV-2061): a display option for the
                         Live-only trail arrows (default off). Same button shape as the
                         layer/Color Map toggles; the indicator mirrors the on-map arrow
                         (gray body, Scout-red outline). -->
                    <LayerToggleTip
                        v-if="!mapStore.adminMode"
                        tip-key="trailArrows"
                        v-model:open="openTip"
                        text="Show arrows along your live trail indicating travel direction."
                    >
                        <button
                            @click="mapStore.toggleTrailArrows()"
                            :aria-pressed="mapStore.trailArrowsEnabled"
                            class="w-full flex items-center gap-2 px-3 py-3 rounded-xl text-sm font-semibold transition-all min-h-[48px] text-left"
                            :style="trailArrowsStyle"
                        >
                            <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 24 24" fill="#6B7280" stroke="#FF6666" stroke-width="2" stroke-linejoin="round">
                                <path d="M12 3l8 18-8-5-8 5z" />
                            </svg>
                            <span>Direction Arrows</span>
                        </button>
                    </LayerToggleTip>

                    <template v-if="mapStore.adminMode">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 pt-2">For all drivers</p>
                        <p class="text-[11px] text-gray-400 leading-snug">Off hides the layer from every Scout driver. Drivers still turn on whatever remains.</p>
                        <label
                            v-for="layer in driverAvailabilityLayers"
                            :key="'driver-'+layer.type"
                            class="flex items-center gap-2 min-h-[44px] text-sm text-gray-700 cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                class="size-4 shrink-0 rounded border-gray-300 accent-[#FF6666]"
                                :checked="mapStore.driverMapLayers.includes(layer.type)"
                                :disabled="mapStore.driverMapLayersSaving"
                                :aria-label="layer.label"
                                @change="mapStore.toggleDriverMapLayer(layer.type)"
                            />
                            <span>{{ layer.label }}</span>
                        </label>
                    </template>
                </div>
            </div>

            <!-- Legend — the map's keys (hot spot, scan paths, "You"), in their own
                 card under Map Layers. The hit-marker key moved to the left column
                 (DEV-2114). Each section shows only when its layer is active, and the
                 whole card hides when nothing applies. -->
            <div v-show="showLegend && (!isCompact || layersExpanded)"
                 class="pointer-events-auto bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge w-[min(280px,calc(100vw-2.5rem))] flex flex-col divide-y divide-gray-100">
                <!-- Live Hit Density legend — each circle covers a ~0.1 mi (160m)
                     area of org-wide live hits (Direct + Staging combined, DEV-2052);
                     color is a warning ramp by hit count (green = lone hit, red = dense
                     cluster). Shown only while the Live Hit Density layer is on
                     (toggled via Map Layers). -->
                <div v-show="mapStore.activeHeatmaps.has('hits')" class="p-4">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Live Hit Density</p>
                    <p class="text-xs leading-snug text-gray-400 mb-2">
                        Each circle covers a ~0.1 mi radius.
                    </p>
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block size-3 rounded-full" style="background-color: #22c55e"></span>
                            <span class="text-xs text-gray-600">1 hit</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block size-3 rounded-full" style="background-color: #eab308"></span>
                            <span class="text-xs text-gray-600">2–4 hits</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block size-3 rounded-full" style="background-color: #ef4444"></span>
                            <span class="text-xs text-gray-600">5+ hits</span>
                        </div>
                    </div>
                </div>

                <!-- Orders legend (DEV-2600) — five Kit bands on one unioned
                     Direct + Staging layer. Each row is clickable and filters
                     paint-time by properties.tier; hiding a band does not refetch.
                     Shown when either order layer is active. -->
                <div v-show="ordersActive" class="p-4">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Orders</p>
                    <div class="flex flex-col gap-1.5">
                        <button
                            v-for="t in ORDER_LEGEND"
                            :key="t.tier"
                            type="button"
                            :aria-pressed="isTierVisible(t.tier)"
                            class="flex items-center gap-1.5 text-left bg-transparent border-0 p-0 cursor-pointer"
                            @click="mapStore.toggleOrderTier(t.tier)"
                        >
                            <span
                                class="inline-block size-3 rounded-sm"
                                :style="{ backgroundColor: t.color }"
                                :class="isTierVisible(t.tier) ? '' : 'opacity-25'"
                            ></span>
                            <span
                                class="text-xs"
                                :class="isTierVisible(t.tier) ? 'text-gray-600' : 'text-gray-300 line-through'"
                            >{{ t.label }}</span>
                        </button>
                    </div>
                </div>

                <!-- Teammate overlay legend (DEV-2199): cyan, distinct from the
                     driver's own scan-path colors below. Driver-only — hidden in
                     Bird's Eye even if the reactive Set briefly carries the type. -->
                <div v-show="teammatesActive && !mapStore.adminMode" class="p-4">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Teammate Activity</p>
                    <div class="flex items-center gap-2">
                        <span class="w-4 h-2 rounded-sm" :style="{ backgroundColor: TEAMMATE_OVERLAY_COLOR }"></span>
                        <span class="text-xs text-gray-600">Today + last 7 days</span>
                    </div>
                </div>

                <!-- Scan path legend (historical only) — today's drive is red, older
                     trips fade out over 60 days (DEV-1960 retired the density gradient). -->
                <div v-show="!mapStore.liveMode" class="p-4">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Scan Paths</p>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-4 h-2 rounded-sm" style="background-color: #FF6666"></span>
                        <span class="text-xs text-gray-600">Today</span>
                    </div>
                    <div v-show="dashboard.range !== 'today'" class="flex items-center gap-1.5">
                        <span class="text-xs text-gray-400">Recent</span>
                        <span class="flex-1 h-2 rounded-sm" style="background: linear-gradient(to right, rgb(30,41,55), rgba(30,41,55,0.1))"></span>
                        <span class="text-xs text-gray-400">60d</span>
                    </div>
                </div>

                <!-- Kit position legend ("You") — identifies which icon on the map
                     is this user. -->
                <div v-if="mapStore.kitPosition && mapStore.kitPosition.lat" class="p-4">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">You</p>
                    <div class="flex items-center gap-2">
                        <span class="flex-shrink-0" v-html="kitLegendIcon"></span>
                        <div class="flex flex-col min-w-0">
                            <span class="text-sm font-medium text-gray-700 truncate leading-tight">{{ kitName }}</span>
                            <span v-if="kitVehicleLabel"
                                  class="text-xs text-gray-400 truncate leading-tight">{{ kitVehicleLabel }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useDashboardStore } from '../stores/dashboard';
import { useMapStore } from '../stores/map';
import { useAuthStore } from '../stores/auth';
import ErrorState from './ErrorState.vue';
import LayerToggleTip from './LayerToggleTip.vue';
import DriverSelector from './DriverSelector.vue';
import { useViewport } from '../composables/useViewport';
import { useCollapsible } from '../composables/useCollapsible';
import { useEchoStatus } from '../composables/useEchoStatus';
import { openProfile } from '../lib/scoutNav';
import { legendIconSvg, vehicleTypeLabel } from '../lib/vehicleIcons';
import { scoutTrack } from '../lib/track';
import { SCOUT_EVENT, SCOUT_PAGE } from '../lib/analyticsEvents';

const dashboard = useDashboardStore();
const mapStore = useMapStore();
const authStore = useAuthStore();
const router = useRouter();

const backUrl = window.__CLARITY_SCOUT_CONFIG__?.backUrl || '/app';

// Mobile-only Profile button (top-right, next to Map Layers) carries the realtime
// connection dot, since the desktop controls widget that used to host it is hidden
// on compact.
const { echoState, echoDotClass } = useEchoStatus();
function navProfile() {
    openProfile(router);
}

// Map-first overlays on small screens (driver app). On compact the top overlays form
// a single-open accordion (group 'scout-overlays', shared with the controls widget):
// opening one collapses the others. Live Hits collapses to a compact pill; the
// Hit-markers key + Legend hide on compact; Map Layers collapses to an icon chip.
const { isCompact } = useViewport();
const { expanded: liveHitsExpanded, toggle: toggleLiveHitsCollapsible } = useCollapsible('scanner_live_hits_expanded', { isCompact, group: 'scout-overlays' });

function toggleLiveHits() {
    scoutTrack(SCOUT_EVENT.LIVE_HITS_TOGGLE, SCOUT_PAGE.DASHBOARD, { action: liveHitsExpanded.value ? 'collapse' : 'expand' });
    toggleLiveHitsCollapsible();
}

// recentHits is scoped by the same followedKitId/selectedKitIds as the stat
// tiles (lib/fetch.js), so a followed or filtered list is not "org-wide".
const liveHitsDescription = computed(() => {
    if (!mapStore.adminMode) {
        return "Your hits that haven't been repossessed yet, in the selected date range — newest first.";
    }
    const scope = mapStore.statsScope;
    if (scope.state === 'followed') {
        return `${scope.driverName}'s hits that haven't been repossessed yet, in the selected date range — newest first.`;
    }
    if (scope.state === 'subset') {
        return `Hits for the ${scope.selectedCount} selected ${scope.driverNoun} that haven't been repossessed yet, in the selected date range — newest first.`;
    }
    return "Org-wide hits that haven't been repossessed yet, in the selected date range — newest first.";
});
// Map Layers — defaults collapsed on every breakpoint (its historical look) and joins
// the accordion; reuses its long-standing persisted key.
const { expanded: layersExpanded, toggle: toggleLayersCollapsible } = useCollapsible('scanner_map_layers_expanded', { isCompact, defaultOpen: false, group: 'scout-overlays' });

function toggleLayers() {
    const opening = !layersExpanded.value;
    toggleLayersCollapsible();
    if (opening && !mapStore.adminMode) {
        authStore.fetchUser().catch(() => {});
    }
}

// The legend card (right side, under Map Layers) is only worth showing when at
// least one of its sections applies: hot spots on, Historical mode (scan paths),
// or a known kit position ("You").
// Spread the Set so the computed tracks its mutations — `.has()` alone on a
// reactive Set doesn't re-run reliably on delete, which left the Orders legend
// visible after both order layers were toggled off (DEV-2031 agent-QA A6).
const ordersActive = computed(() =>
    [...mapStore.activeHeatmaps].some(t => t === 'hotlist' || t === 'staging')
);

// DEV-2600: five-band Kit scale. En dashes match the previous legend copy.
// Spread the Set — `.has()` alone misses deletes (DEV-2031 A6).
const ORDER_LEGEND = [
    { tier: 'tier_1', color: '#E1BEE7', label: '1 address' },
    { tier: 'tier_2_4', color: '#CE93D8', label: '2–4 addresses' },
    { tier: 'tier_5_9', color: '#AB47BC', label: '5–9 addresses' },
    { tier: 'tier_10_24', color: '#7B1FA2', label: '10–24 addresses' },
    { tier: 'tier_25_plus', color: '#4A148C', label: '25+ addresses' },
];

function isTierVisible(tier) {
    return [...mapStore.visibleOrderTiers].includes(tier);
}

// DEV-2199: same reactive-Set-spread guard as ordersActive above.
const teammatesActive = computed(() =>
    [...mapStore.activeHeatmaps].some(t => t === 'teammates')
);

const showLegend = computed(() =>
    mapStore.activeHeatmaps.has('hits')
    || ordersActive.value
    || teammatesActive.value
    || !mapStore.liveMode
    || !!(mapStore.kitPosition && mapStore.kitPosition.lat)
);

// Kit identity legend — mirrors the map marker so the user can confirm
// "the blue car on the map is me".
const kitName = computed(() => authStore.kit?.name || 'Scanner');
const kitLegendIcon = computed(() => legendIconSvg(mapStore.kitPosition?.vehicle_type ?? null, 22));
const kitVehicleLabel = computed(() => vehicleTypeLabel(mapStore.kitPosition?.vehicle_type ?? null));

function formatHitTime(iso) {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString([], {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
        });
    } catch {
        return '';
    }
}

// DEV-2137: the Live Hits list labels each row by hit type ('L' Direct Live, 'S'
// Staging Live) — the same pill MapShell renders in the hit detail sheet — rather
// than the old "Open" status text. An unknown type resolves to '' and renders nothing.
const HIT_TYPE_LABELS = { L: 'Direct Live', S: 'Staging Live' };

function hitTypeLabel(hit) {
    return HIT_TYPE_LABELS[hit?.hit_type] ?? '';
}

// Map Layers help-text popovers: the tip-key of the open toggle (null = none).
// Mirrors MapStatsBar — each LayerToggleTip's own tap is .stop'd, so an outside
// tap reaching the window closes whichever popover is open.
const openTip = ref(null);
const closeTip = () => {
    openTip.value = null;
};
onMounted(() => window.addEventListener('click', closeTip));
onBeforeUnmount(() => window.removeEventListener('click', closeTip));

// DEV-2199: teammate overlay cyan — distinct from own-line red, aging
// slate-black, Bird's-Eye crimson, and every Zone::PRESET_COLORS boundary color.
const TEAMMATE_OVERLAY_COLOR = '#0891B2';

const ALL_HEATMAP_LAYERS = [
    {
        type: 'hits',
        label: 'Live Hit Density',
        color: '#FF7F50',
        tip: 'Org-wide density of live hits — Direct and Staging combined — over the selected period. Not limited to your kit, and not historical.',
    },
    {
        type: 'hotlist',
        label: 'Direct Orders',
        color: '#FF7F50',
        tip: 'Addresses on active (Direct) orders your company is currently working.',
    },
    {
        type: 'staging',
        label: 'Staging Orders',
        color: '#FF7F50',
        tip: 'Addresses on Staging orders — accounts queued but not yet active.',
    },
    {
        type: 'teammates',
        label: 'Teammate Activity',
        color: TEAMMATE_OVERLAY_COLOR,
        tip: "Other drivers' driven lines for today and the last 7 days, within your current map view. A snapshot, not live.",
    },
];

// Org Map: the hit-density layer ignores kit_ids entirely (lib/fetch.js skips the
// scope block for /map/heatmap/*), so it stays org-wide even while a driver is
// followed — say that, rather than the driver-facing "not limited to your kit".
const ADMIN_LAYER_TIPS = {
    hits: 'Org-wide density of live hits — Direct and Staging combined — over the selected period. Not narrowed by the driver selector or a followed driver, and not historical.',
};

const heatmapLayers = computed(() => {
    if (mapStore.adminMode) {
        return ALL_HEATMAP_LAYERS
            .filter((layer) => layer.type !== 'teammates')
            .map((layer) => (ADMIN_LAYER_TIPS[layer.type] ? { ...layer, tip: ADMIN_LAYER_TIPS[layer.type] } : layer));
    }

    const allowed = new Set(authStore.enabledMapLayers);

    return ALL_HEATMAP_LAYERS.filter((layer) => allowed.has(layer.type));
});

const driverAvailabilityLayers = ALL_HEATMAP_LAYERS;

function layerStyle(layer) {
    const active = mapStore.activeHeatmaps.has(layer.type);
    if (active) {
        return {
            backgroundColor: layer.color + '18',
            color: layer.color,
            border: `2px solid ${layer.color}40`,
        };
    }
    return {
        backgroundColor: 'transparent',
        color: '#6B7280',
        border: '2px solid transparent',
    };
}

// On/off styling for the Color Map toggle — mirrors layerStyle's active look
// using an indigo accent, since it's a display option rather than a data layer.
const COLOR_MAP_ACCENT = '#6366F1';
const colorMapStyle = computed(() => mapStore.colorMapEnabled
    ? {
        backgroundColor: COLOR_MAP_ACCENT + '18',
        color: COLOR_MAP_ACCENT,
        border: `2px solid ${COLOR_MAP_ACCENT}40`,
    }
    : {
        backgroundColor: 'transparent',
        color: '#6B7280',
        border: '2px solid transparent',
    });

// On/off styling for the Direction Arrows toggle — same indigo active look as
// the sibling Color Map display toggle (DEV-2061).
const trailArrowsStyle = computed(() => mapStore.trailArrowsEnabled
    ? {
        backgroundColor: COLOR_MAP_ACCENT + '18',
        color: COLOR_MAP_ACCENT,
        border: `2px solid ${COLOR_MAP_ACCENT}40`,
    }
    : {
        backgroundColor: 'transparent',
        color: '#6B7280',
        border: '2px solid transparent',
    });

function selectHitFromCard(hit) {
    scoutTrack(SCOUT_EVENT.HIT_FOCUS, SCOUT_PAGE.MAP, { action: 'select', source: 'card' });
    mapStore.focusHit(hit);
}

// DEV-2594: collapse Live Hits whenever a hit is selected — from this list OR from a
// map logo marker (setSelectedHit). Keeps Hit Details visible on compact, where the
// expanded card is nearly full-width and otherwise covers the sheet; also clears the
// top-left chrome that can sit over the newest row on desktop.
watch(() => mapStore.selectedHit, (hit) => {
    if (hit && liveHitsExpanded.value) {
        liveHitsExpanded.value = false;
    }
});

function handleToggleHeatmap(type) {
    scoutTrack(SCOUT_EVENT.MAP_LAYER_TOGGLE, SCOUT_PAGE.MAP, {
        action: 'toggle',
        layer: type,
        visible: !mapStore.activeHeatmaps.has(type),
    });
    mapStore.toggleHeatmap(type);
}

onMounted(() => {
    mapStore.setViewMode('dashboard');
});
</script>

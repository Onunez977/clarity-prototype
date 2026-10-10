<template>
    <!-- Date control (DEV-2076): one combined Today/live control. Today turns
         green and behaves as live (kit position + trail + today's hits); picking
         Yesterday–30d drops into that historical window. Lives in the bottom
         sheet, above the stat row. -->
    <div class="flex items-center gap-1 rounded-xl bg-gray-100 p-1" role="group" aria-label="Date range">
        <button
            v-for="option in visibleRangeOptions"
            :key="option.value"
            type="button"
            :aria-label="option.label"
            :aria-pressed="isRangeActive(option.value)"
            @click="selectRange(option.value)"
            class="flex-1 inline-flex items-center justify-center gap-1 rounded-lg px-1 py-2.5 text-[13px] font-semibold leading-none transition-colors"
            :class="rangeButtonClass(option.value)"
        >
            <span v-if="option.value === 'today' && mapStore.liveMode && !isCompact" class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
            </span>
            {{ option.short }}
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useDashboardStore } from '../stores/dashboard';
import { useMapStore } from '../stores/map';
import { useViewport } from '../composables/useViewport';
import { rangeOptions, driverRangeOptions } from '../lib/formatters';
import { scoutTrack } from '../lib/track';
import { SCOUT_EVENT, SCOUT_PAGE } from '../lib/analyticsEvents';

const dashboard = useDashboardStore();
const mapStore = useMapStore();
// Hide the pinging "live" dot on the Today pill on compact to save horizontal space.
const { isCompact } = useViewport();

// DEV-2306: both apps show the same five pills; the arrays are kept separate so
// admin and driver can diverge again without a shared-array refactor.
const visibleRangeOptions = computed(() =>
    mapStore.adminMode ? rangeOptions : driverRangeOptions,
);

// Today is the live view — active (green) when liveMode is on; Yesterday–30d are
// historical, active only when liveMode is off and selected.
function isRangeActive(value) {
    if (value === 'today') {
        return mapStore.liveMode;
    }
    return !mapStore.liveMode && dashboard.range === value;
}

function selectRange(value) {
    // Only a real change is an action — re-tapping the active range is a no-op.
    if (!isRangeActive(value)) {
        scoutTrack(SCOUT_EVENT.DATE_RANGE, SCOUT_PAGE.DASHBOARD, { action: 'date-range-changed', range: value });
    }
    if (value === 'today') {
        if (!mapStore.liveMode) {
            mapStore.setLiveMode(true);
        }
        dashboard.setRange('today');
        return;
    }
    if (mapStore.liveMode) {
        mapStore.setLiveMode(false);
    }
    // DEV-2185: 15/30-day scan paths are zoom-gated (PATH_LAYERS_MIN_ZOOM in
    // ScannerMap.vue) — snap the camera in so the layer is immediately visible
    // and fetched rather than landing hidden below the floor.
    if (['last_15', 'last_30'].includes(value)) {
        mapStore.requestPathZoomSnap();
    }
    dashboard.setRange(value);
}

// Today active = green (live); Yesterday–30d active = white. Inactive Today
// keeps a green-tinted hint so it still reads as the live option.
function rangeButtonClass(value) {
    if (!isRangeActive(value)) {
        return value === 'today'
            ? 'text-emerald-700 hover:text-emerald-800'
            : 'text-gray-500 hover:text-gray-700';
    }
    return value === 'today'
        ? 'bg-emerald-500 text-white shadow-sm'
        : 'bg-white text-gray-900 shadow-sm';
}
</script>

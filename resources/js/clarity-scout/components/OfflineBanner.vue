<template>
    <!-- Slim, non-blocking amber pill. Positioned by its parent (the bottom stat
         stack in App.vue) to sit just above the stat sheet while offline in Live
         mode; pointer-events-auto so the pill itself stays tappable. -->
    <div
        v-if="isOffline && isLive"
        role="status"
        aria-live="polite"
        class="pointer-events-auto inline-flex items-center gap-2 max-w-[92vw] rounded-2xl shadow-scout-panel bg-amber-500/95 text-white px-4 py-2 text-sm font-medium"
    >
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 010 12.728m-12.728 0a9 9 0 010-12.728m9.9 2.829a5 5 0 010 7.07m-7.07 0a5 5 0 010-7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z" />
        </svg>
        <span>
            You're offline — showing last known data<template v-if="lastConnectedLabel"> · last connected {{ lastConnectedLabel }}</template>
        </span>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useConnectionStatus } from '../composables/useConnectionStatus';
import { useMapStore } from '../stores/map';

const { isOffline, offlineSince } = useConnectionStatus();
const mapStore = useMapStore();

// liveMode is the canonical live-vs-historical store getter (used by App.vue and
// MapStatsBar). Preferred over route.query.day, which only signals trip/day view.
const isLive = computed(() => mapStore.liveMode);

const lastConnectedLabel = computed(() =>
    offlineSince.value
        ? new Date(offlineSince.value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
        : null
);
</script>

<template>
    <!-- Inline metrics row inside the top navbar (DEV-1959; relocated from a
         floating card into the header) — scans, hits, trips for the
         active range. Compact even cells so it fits a phone-width navbar.
         Each tile doubles as a help-text trigger: hover on desktop, tap to
         toggle on mobile (mirrors the client analytics dashboard tooltips). -->
    <div class="flex items-stretch transition-opacity" :class="{ 'opacity-60 saturate-50': showStale }">
        <div
            v-for="(stat, i) in stats"
            :key="stat.label"
            class="relative flex-1 flex flex-col items-center justify-center px-2.5 md:px-8 text-center border-gray-200 cursor-help select-none"
            :class="{ 'border-l': i > 0 }"
            tabindex="0"
            role="button"
            :aria-label="`${stat.label}: ${stat.description}`"
            @mouseenter="openTip = i"
            @mouseleave="openTip = null"
            @focus="openTip = i"
            @blur="openTip = null"
            @click.stop="openTip = openTip === i ? null : i"
        >
            <span class="relative inline-flex items-center justify-center">
                <span class="text-base md:text-xl font-bold text-gray-900 leading-none">{{ stat.value }}</span>

                <!-- Live increase indicators (Today mode only): a green ▲ for new
                     scans, a red ping dot for new hits. Both flash briefly then clear. -->
                <transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-1"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-300"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-1"
                >
                    <span
                        v-if="stat.indicator === 'arrow' && stat.bumped"
                        class="absolute -right-3 top-1/2 -translate-y-1/2 text-[10px] md:text-xs leading-none text-emerald-500"
                        aria-hidden="true"
                    >▲</span>
                </transition>

                <transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-300"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <span
                        v-if="stat.indicator === 'dot' && stat.bumped"
                        class="absolute -right-3 top-0 flex h-2 w-2"
                        aria-hidden="true"
                    >
                        <span
                            v-if="!reducedMotion"
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-75"
                        ></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                    </span>
                </transition>
            </span>
            <span class="text-[9px] md:text-[11px] font-bold text-gray-400 uppercase tracking-wider mt-0.5 md:mt-1 whitespace-nowrap">{{ stat.label }}</span>

            <transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-1"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-1"
            >
                <!-- Renders above the tile — the stat bar sits at the bottom of
                     the viewport, so a top-full tooltip would be clipped. -->
                <div
                    v-if="openTip === i"
                    class="absolute bottom-full mb-2 w-56 rounded-lg bg-gray-900 px-3 py-2 text-xs leading-snug text-white shadow-lg z-50 pointer-events-none normal-case tracking-normal text-left font-medium"
                    :class="i === 0 ? 'left-0' : i === stats.length - 1 ? 'right-0' : 'left-1/2 -translate-x-1/2'"
                >
                    {{ stat.description }}
                </div>
            </transition>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useAuthStore } from '../stores/auth';
import { useDashboardStore } from '../stores/dashboard';
import { useMapStore } from '../stores/map';
import { useCountUp } from '../composables/useCountUp';
import { useConnectionStatus } from '../composables/useConnectionStatus';

const auth = useAuthStore();
const dashboard = useDashboardStore();
const mapStore = useMapStore();

const { isOffline } = useConnectionStatus();
// Admin (Bird's Eye) never connects Echo, so isOffline flips true after ~5s and
// would leave tiles permanently stale-styled — gate the stale look off in admin
// (mirrors App.vue's offlineStale).
const showStale = computed(() => isOffline.value && mapStore.liveMode && !mapStore.adminMode);

const fmt = (n) => (n ?? 0).toLocaleString();

const reducedMotion = typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// Index of the tile whose help-text popover is open (null = none).
const openTip = ref(null);

// Tapping anywhere else closes an open popover (the tile's own click is
// .stop'd, so this only fires for outside taps).
const closeTip = () => {
    openTip.value = null;
};
onMounted(() => window.addEventListener('click', closeTip));
onBeforeUnmount(() => window.removeEventListener('click', closeTip));

// Live mode reflects "now", so the row tracks today's numbers regardless of any
// historical range left selected behind it; Historical shows the picked range.
const source = computed(() =>
    mapStore.liveMode ? (dashboard.statsByRange.today ?? null) : dashboard.stats
);

// Each tile rolls toward its target value (odometer effect) regardless of range.
const scans = useCountUp(() => source.value?.scans_today);
const directHits = useCountUp(() => source.value?.direct_hits);
const stagingHits = useCountUp(() => source.value?.staging_hits);
const trips = useCountUp(() => source.value?.trip_count);

// Transient "just increased" flags for the live indicators. Watching the today
// object fields directly (not `source`) means they flash only on genuine
// in-place delta increments — never on a range switch or toggling back to Today,
// which swap `source` to a different object.
const scansBumped = ref(false);
const directBumped = ref(false);
const stagingBumped = ref(false);

const bumpTimers = {};

function bumpOnIncrease(flag, key) {
    return (newVal, oldVal) => {
        if (!mapStore.liveMode || oldVal == null || newVal <= oldVal) {
            return;
        }
        flag.value = true;
        clearTimeout(bumpTimers[key]);
        bumpTimers[key] = setTimeout(() => {
            flag.value = false;
        }, 3500);
    };
}

watch(() => dashboard.statsByRange.today?.scans_today, bumpOnIncrease(scansBumped, 'scans'));
watch(() => dashboard.statsByRange.today?.direct_hits, bumpOnIncrease(directBumped, 'direct'));
watch(() => dashboard.statsByRange.today?.staging_hits, bumpOnIncrease(stagingBumped, 'staging'));

onBeforeUnmount(() => {
    Object.values(bumpTimers).forEach(clearTimeout);
});

// "Selected date range" reads correctly in both modes — Live mode's effective
// range is today, so the descriptions stay range-neutral as the picker changes.
const stats = computed(() => [
    {
        label: 'Scans',
        value: fmt(scans.display.value),
        description: mapStore.adminMode
            ? 'The total number of license plates read over the selected date range.'
            : 'The total number of license plates your kit read over the selected date range.',
        indicator: 'arrow',
        bumped: scansBumped.value,
    },
    // DEV-2053: split the kit/date-scoped hit count into Direct Live and Staging
    // Live so drivers can reconcile each against what they scanned. Both are
    // driver-scoped, distinct from the org-wide Hit Hotspots heatmap on the map.
    {
        label: 'Direct Live Hits',
        value: fmt(directHits.display.value),
        description: mapStore.adminMode
            ? 'Plates that matched a live hotlist your company is actively working.'
            : 'Plates you scanned that matched a live hotlist your company is actively working.',
        indicator: 'dot',
        bumped: directBumped.value,
    },
    {
        label: 'Staging Live Hits',
        value: fmt(stagingHits.display.value),
        description: mapStore.adminMode
            ? 'Plates that matched a staging hotlist.'
            : 'Plates you scanned that matched a staging hotlist.',
        indicator: 'dot',
        bumped: stagingBumped.value,
    },
    // Repos is pulled from the stat row (all breakpoints + ranges) until the
    // direct/staging split is sorted — repos_affected is only meaningful for
    // direct live hits, not staging (DEV-2138).
    // Trips stat is hidden behind the CLARITY_SCOUT_TRIPS flag for the initial rollout.
    ...(auth.tripsEnabled ? [{
        label: 'Trips',
        value: fmt(trips.display.value),
        description: mapStore.adminMode
            ? 'Driving trips logged over the selected date range.'
            : 'Driving trips your kit logged over the selected date range.',
    }] : []),
]);
</script>

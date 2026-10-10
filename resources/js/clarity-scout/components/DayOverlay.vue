<template>
    <div class="fixed inset-0 pointer-events-none" :inert="!!selectedHit">
        <!-- Error overlay -->
        <div v-if="trips.error" class="absolute left-5 right-5 z-20 pointer-events-auto"
             :style="{ top: isCompact ? 'calc(env(safe-area-inset-top) + 12px)' : 'calc(env(safe-area-inset-top) + var(--scout-controls-h, 132px) + 24px)' }">
            <ErrorState :message="trips.error" @retry="reload" />
        </div>

        <!-- Loading skeletons -->
        <template v-if="trips.loading && !dayDetail">
            <div class="absolute left-5 z-20 flex flex-col gap-3 pointer-events-none"
                 :style="{ top: isCompact ? 'calc(env(safe-area-inset-top) + 12px)' : 'calc(env(safe-area-inset-top) + var(--scout-controls-h, 132px) + 24px)' }">
                <div class="w-[280px] h-[100px] bg-white/60 backdrop-blur-sm rounded-2xl animate-pulse"></div>
                <div class="w-[280px] h-[100px] bg-white/60 backdrop-blur-sm rounded-2xl animate-pulse"></div>
                <div class="flex gap-3 w-[280px]">
                    <div class="flex-1 h-[76px] bg-white/60 backdrop-blur-sm rounded-2xl animate-pulse"></div>
                    <div class="flex-1 h-[76px] bg-white/60 backdrop-blur-sm rounded-2xl animate-pulse"></div>
                </div>
            </div>
        </template>

        <template v-else-if="dayDetail">
            <!-- Floating stat cards — top left, below header -->
            <div class="absolute left-5 z-20 flex flex-col gap-3 pointer-events-none"
                 :style="{ top: isCompact ? 'calc(env(safe-area-inset-top) + 12px)' : 'calc(env(safe-area-inset-top) + var(--scout-controls-h, 132px) + 24px)' }">
                <FloatingStatCard
                    :value="dayDetail.totals.scans"
                    label="Scans"
                    size="large"
                    icon="camera"
                />
                <div class="pointer-events-auto cursor-pointer" @click="openHitsPanel">
                    <FloatingStatCard
                        :value="dayDetail.totals.hits"
                        label="Hits"
                        size="large"
                        icon="alert"
                    />
                </div>
                <div class="flex gap-3 w-[280px]">
                    <FloatingStatCard
                        :value="dayDetail.totals.miles_driven"
                        label="Miles"
                        size="small"
                        suffix="mi"
                    />
                    <FloatingStatCard
                        :value="formatDuration(dayDetail.totals.active_duration_minutes)"
                        label="Duration"
                        size="small"
                    />
                </div>
            </div>

            <!-- Floating map layers — top right -->
            <div class="absolute right-5 z-20 pointer-events-auto"
                 style="top: calc(env(safe-area-inset-top) + 12px)">
                <div class="bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge p-5 w-[200px]">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4">Map Layers</p>
                    <div class="flex flex-col gap-2">
                        <button
                            v-for="layer in heatmapLayers"
                            :key="layer.type"
                            @click="handleToggleHeatmap(layer.type)"
                            class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold transition-all min-h-[48px]"
                            :style="layerStyle(layer)"
                        >
                            <span class="w-3 h-3 rounded-full flex-shrink-0"
                                  :style="{ backgroundColor: layer.color }"></span>
                            {{ layer.label }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Floating day overview bar — above bottom nav -->
            <div class="fixed bottom-28 left-1/2 -translate-x-1/2 z-20 pointer-events-auto" style="min-width: 380px">
                <div
                    @click="openTripsSheet"
                    @pointerdown="onBarPointerDown"
                    class="w-full bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge hover:bg-white transition-colors touch-none cursor-pointer"
                >
                    <!-- Drag handle -->
                    <div class="flex justify-center pt-2">
                        <div class="w-10 h-1 rounded-full bg-gray-300"></div>
                    </div>
                    <div class="px-6 py-2.5 flex items-center justify-between">
                        <span class="text-base font-bold text-gray-900">{{ formatDate(dayDetail.date) }}</span>
                        <span class="flex items-center gap-2">
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                                {{ dayDetail.trips.length }} {{ dayDetail.trips.length === 1 ? 'trip' : 'trips' }}
                            </span>
                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                            </svg>
                        </span>
                    </div>
                </div>
            </div>
        </template>

        <!-- Bottom sheet for trips -->
        <div class="pointer-events-auto">
            <BottomSheet v-model:open="tripsSheetOpen" title="Route Overview" contentMaxHeight="280px">
                <template v-if="dayDetail">
                    <p class="text-sm text-gray-500 mb-3">{{ formatDate(dayDetail.date) }}</p>

                    <!-- Trip cards -->
                    <div class="space-y-2">
                        <div
                            v-for="(trip, i) in dayDetail.trips"
                            :key="trip.trip_index"
                            class="flex items-center gap-2 bg-white rounded-xl border border-gray-200 px-3 py-3 transition-all min-h-[44px]"
                            :class="activeTripIndex === i
                                ? 'border-blue-300 ring-2 ring-blue-200'
                                : 'hover:border-gray-300 hover:shadow-sm'"
                        >
                            <!-- Tap body to highlight on map -->
                            <button
                                @click="toggleTrip(i)"
                                class="flex items-center gap-3 flex-1 min-w-0 text-left"
                            >
                                <span class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold flex-shrink-0"
                                      :class="activeTripIndex === i
                                          ? 'bg-blue-600 text-white'
                                          : 'bg-gray-100 text-gray-600'">
                                    {{ trip.trip_index + 1 }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-sm text-gray-900">Trip {{ trip.trip_index + 1 }}</p>
                                    <div class="flex gap-3 text-xs text-gray-500 mt-0.5">
                                        <span>{{ trip.miles_driven }} mi</span>
                                        <span>{{ formatDuration(trip.active_duration_minutes) }}</span>
                                        <span v-if="trip.hits" class="text-red-500 font-medium">{{ trip.hits }} {{ trip.hits === 1 ? 'hit' : 'hits' }}</span>
                                    </div>
                                </div>
                                <span class="text-xs text-gray-400 flex-shrink-0">{{ formatTime(trip.started_at) }}</span>
                            </button>

                            <!-- Chevron to navigate to the single trip view -->
                            <button
                                @click="navigateToTrip(trip)"
                                class="p-2 rounded-lg hover:bg-gray-100 transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center flex-shrink-0"
                            >
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- View Hits button -->
                    <button
                        v-if="allHits.length"
                        @click="openHitsPanel"
                        class="w-full mt-4 py-3.5 rounded-xl bg-blue-600 text-white text-sm font-semibold flex items-center justify-center gap-2 hover:bg-blue-700 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
                        </svg>
                        View {{ allHits.length }} {{ allHits.length === 1 ? 'Hit' : 'Hits' }}
                    </button>
                </template>
            </BottomSheet>
        </div>

        <!-- Side panel for hits -->
        <div class="pointer-events-auto">
            <SidePanel v-model:open="hitsPanelOpen" title="Hits" width="480px">
                <template v-if="dayDetail">
                    <!-- Summary -->
                    <div class="flex items-center gap-2 mb-4">
                        <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                            {{ dayDetail.trips.length }} {{ dayDetail.trips.length === 1 ? 'trip' : 'trips' }}
                        </span>
                        <span class="text-sm text-gray-500">{{ allHits.length }} {{ allHits.length === 1 ? 'hit' : 'hits' }} total</span>
                    </div>

                    <EmptyState
                        v-if="!allHits.length"
                        message="No hits during this day"
                        icon="hits"
                    />

                    <template v-else>
                        <div
                            v-for="group in groupedHits"
                            :key="group.plate"
                            class="mb-3 last:mb-0"
                        >
                            <!-- Plate group header -->
                            <button
                                @click="togglePlate(group.plate)"
                                class="w-full flex items-center gap-2 px-3 py-2.5 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors"
                            >
                                <span class="w-1 self-stretch rounded-full bg-red-500 flex-shrink-0"></span>
                                <span class="font-mono font-semibold text-sm">{{ group.plate }}</span>
                                <span class="ml-auto flex items-center gap-2">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-red-100 text-red-700">
                                        {{ group.hits.length }} {{ group.hits.length === 1 ? 'hit' : 'hits' }}
                                    </span>
                                    <svg
                                        class="w-4 h-4 text-gray-400 transition-transform"
                                        :class="{ 'rotate-180': isExpanded(group.plate) }"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </span>
                            </button>

                            <!-- Individual hit entries (compact) -->
                            <div v-if="isExpanded(group.plate)" class="ml-4 border-l-2 border-gray-100 pl-3 mt-1">
                                <div
                                    v-for="(hit, i) in group.hits"
                                    :key="i"
                                    class="flex items-center gap-2 py-2 border-b border-gray-100 last:border-0 cursor-pointer"
                                    @click="selectedHit = hit"
                                >
                                    <img
                                        :src="hit.plate_image"
                                        alt="Plate"
                                        class="w-[60px] h-[36px] object-cover rounded border border-gray-200 flex-shrink-0"
                                        @load="$event.target.style.display = ''"
                                        @error="$event.target.style.display = 'none'"
                                    />
                                    <img
                                        :src="hit.vehicle_image"
                                        alt="Vehicle"
                                        class="w-[60px] h-[36px] object-cover rounded border border-gray-200 flex-shrink-0"
                                        @load="$event.target.style.display = ''"
                                        @error="$event.target.style.display = 'none'"
                                    />
                                    <span class="text-xs text-gray-500 ml-auto flex-shrink-0">{{ formatTime(hit.time) }}</span>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
            </SidePanel>
        </div>

        <!-- Hit details sheet — teleported to body so it renders OUTSIDE the inert
             overlay root, restoring close/drag/backdrop dismissal (safe: fixed-positioned). -->
        <Teleport to="body">
            <BottomSheet
                :open="!!selectedHit"
                title="Hit details"
                contentMaxHeight="280px"
                @update:open="selectedHit = null"
            >
                <template v-if="selectedHit">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="font-mono font-semibold text-lg">{{ selectedHit.plate }}</p>
                        <span
                            v-if="hitTypeLabel"
                            class="text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full"
                            :class="selectedHit.type === 'S'
                                ? 'bg-amber-100 text-amber-700'
                                : 'bg-blue-100 text-blue-700'"
                        >{{ hitTypeLabel }}</span>
                    </div>
                    <p class="text-sm text-gray-400 mb-2">{{ formatTime(selectedHit.time) }}</p>

                    <!-- Vehicle info row (all fields nullable) -->
                    <p
                        v-if="selectedHit.vehicle_year || selectedHit.vehicle_make || selectedHit.vehicle_model || selectedHit.hotlist_us_state"
                        class="text-sm text-gray-600 mb-4"
                    >
                        <span v-if="selectedHit.vehicle_year">{{ selectedHit.vehicle_year }}</span>
                        <span v-if="selectedHit.vehicle_make"> {{ selectedHit.vehicle_make }}</span>
                        <span v-if="selectedHit.vehicle_model"> {{ selectedHit.vehicle_model }}</span>
                        <span v-if="selectedHit.hotlist_us_state" class="text-gray-400"> · {{ selectedHit.hotlist_us_state }}</span>
                    </p>

                    <div class="space-y-3">
                        <img
                            :src="selectedHit.plate_image"
                            alt="Plate"
                            class="w-full rounded-lg border border-gray-200"
                            @load="$event.target.style.display = ''"
                            @error="$event.target.style.display = 'none'"
                        />
                        <img
                            :src="selectedHit.vehicle_image"
                            alt="Vehicle"
                            class="w-full rounded-lg border border-gray-200"
                            @load="$event.target.style.display = ''"
                            @error="$event.target.style.display = 'none'"
                        />
                    </div>
                </template>
            </BottomSheet>
        </Teleport>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useViewport } from '../composables/useViewport';
import { useRouter } from 'vue-router';
import { useTripsStore } from '../stores/trips';
import { useAuthStore } from '../stores/auth';
import { useMapStore } from '../stores/map';
import FloatingStatCard from './FloatingStatCard.vue';
import SidePanel from './SidePanel.vue';
import BottomSheet from './BottomSheet.vue';
import EmptyState from './EmptyState.vue';
import ErrorState from './ErrorState.vue';
import { openTrip } from '../lib/scoutNav';
import { formatDate, formatTime, formatDuration } from '../lib/formatters';

const router = useRouter();
const trips = useTripsStore();
const mapStore = useMapStore();
const authStore = useAuthStore();
// On compact the controls widget is hidden (so --scout-controls-h is unpublished);
// anchor the overlay column near the top instead of offsetting by that height.
const { isCompact } = useViewport();

const dayDetail = computed(() => trips.dayDetail);
const selectedHit = ref(null);

// DEV-2052/DEV-2058: range-hit markers carry hit_type ('L' Direct Live, 'S' Staging
// Live) so the sheet can label which type was tapped. Trip markers carry no hit_type —
// fall back to nothing rather than guessing. Mirrors MapShell's mapping.
const hitTypeLabel = computed(() => ({
    L: 'Direct Live',
    S: 'Staging Live',
}[selectedHit.value?.type] ?? ''));

// Panel state — mutually exclusive
const tripsSheetOpen = ref(false);
const hitsPanelOpen = ref(false);

function openTripsSheet() {
    hitsPanelOpen.value = false;
    tripsSheetOpen.value = true;
}

function openHitsPanel() {
    tripsSheetOpen.value = false;
    hitsPanelOpen.value = true;
}

// Trip highlight selection lives in the map store (the persistent map reads it).
const activeTripIndex = computed(() => mapStore.activeTripIndex);

const allHits = computed(() => dayDetail.value?.hits_detail ?? []);

const expandedPlates = ref(new Set());

const groupedHits = computed(() => {
    const map = new Map();
    for (const hit of allHits.value) {
        const key = hit.plate || 'Unknown';
        if (!map.has(key)) {
            map.set(key, { plate: key, hits: [] });
        }
        map.get(key).hits.push(hit);
    }
    return Array.from(map.values());
});

function isExpanded(plate) {
    const group = groupedHits.value.find(g => g.plate === plate);
    if (group && group.hits.length <= 2) return true;
    return expandedPlates.value.has(plate);
}

function togglePlate(plate) {
    const s = new Set(expandedPlates.value);
    if (s.has(plate)) {
        s.delete(plate);
    } else {
        s.add(plate);
    }
    expandedPlates.value = s;
}

const heatmapLayers = computed(() => [
    { type: 'hits', label: 'Hit Hotspots', color: '#FF7F50' },
    { type: 'hotlist', label: 'Direct Orders', color: '#FF7F50' },
    { type: 'staging', label: 'Staging Orders', color: '#FF7F50' },
].filter((layer) => authStore.enabledMapLayers.includes(layer.type)));

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

function handleToggleHeatmap(type) {
    mapStore.toggleHeatmap(type);
}

function toggleTrip(index) {
    mapStore.setSelectedTripIndex(mapStore.selectedTripIndex === index ? null : index);
}

function navigateToTrip(trip) {
    openTrip(router, dayDetail.value.date, trip.trip_index);
}

// Swipe-up on overview bar to open trips sheet
function onBarPointerDown(e) {
    if (e.button !== 0) return;
    const startY = e.clientY;
    e.target.setPointerCapture(e.pointerId);

    const onMove = (ev) => {
        if (startY - ev.clientY > 30) {
            openTripsSheet();
            e.target.removeEventListener('pointermove', onMove);
            e.target.removeEventListener('pointerup', onUp);
        }
    };
    const onUp = () => {
        e.target.removeEventListener('pointermove', onMove);
        e.target.removeEventListener('pointerup', onUp);
    };

    e.target.addEventListener('pointermove', onMove);
    e.target.addEventListener('pointerup', onUp);
}

function reload() {
    const d = dayDetail.value?.date;
    trips.dayDetail = null;
    if (d) trips.fetchDayDetail(d);
}

// Reset transient UI when the loaded day changes (ScoutHome owns the fetch).
watch(() => trips.dayDetail?.date, () => {
    selectedHit.value = null;
    tripsSheetOpen.value = false;
    hitsPanelOpen.value = false;
    expandedPlates.value = new Set();
});
</script>

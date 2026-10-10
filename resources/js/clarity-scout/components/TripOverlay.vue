<template>
    <div class="fixed inset-0 pointer-events-none" :inert="!!selectedHit">
        <!-- Error overlay -->
        <div v-if="trips.error" class="absolute left-5 right-5 z-20 pointer-events-auto"
             :style="{ top: isCompact ? 'calc(env(safe-area-inset-top) + 12px)' : 'calc(env(safe-area-inset-top) + var(--scout-controls-h, 132px) + 24px)' }">
            <ErrorState :message="trips.error" @retry="reload" />
        </div>

        <!-- Loading skeletons -->
        <template v-if="trips.loading && !tripDetail">
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

        <template v-else-if="tripDetail">
            <!-- Floating stat cards — top left, below header -->
            <div class="absolute left-5 z-20 flex flex-col gap-3 pointer-events-none"
                 :style="{ top: isCompact ? 'calc(env(safe-area-inset-top) + 12px)' : 'calc(env(safe-area-inset-top) + var(--scout-controls-h, 132px) + 24px)' }">
                <FloatingStatCard
                    :value="tripDetail.scans"
                    label="Scans"
                    size="large"
                    icon="camera"
                />
                <FloatingStatCard
                    :value="tripDetail.hits"
                    label="Hits"
                    size="large"
                    icon="alert"
                />
                <div class="flex gap-3 w-[280px]">
                    <FloatingStatCard
                        :value="tripDetail.miles_driven"
                        label="Miles"
                        size="small"
                        suffix="mi"
                    />
                    <FloatingStatCard
                        :value="formatDuration(tripDetail.active_duration_minutes)"
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

            <!-- Floating trip overview bar — above bottom nav -->
            <div class="fixed bottom-28 left-1/2 -translate-x-1/2 z-20 pointer-events-auto" style="min-width: 380px">
                <button
                    @click="panelOpen = true"
                    class="w-full bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge px-6 py-4 flex items-center gap-4 hover:bg-white transition-colors"
                >
                    <span class="text-base font-bold text-gray-900">{{ formatDate(tripDetail.date) }}</span>
                    <span class="text-sm text-gray-500">Trip {{ tripDetail.trip_index + 1 }}</span>
                    <span class="text-sm text-gray-500">{{ tripDetail.scans }} scans</span>
                    <span class="text-sm text-gray-500">{{ hits.length }} {{ hits.length === 1 ? 'hit' : 'hits' }}</span>
                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
            </div>
        </template>

        <!-- Slide-out side panel for hits -->
        <div class="pointer-events-auto">
            <SidePanel v-model:open="panelOpen" title="Hits During Trip" width="480px">
                <EmptyState
                    v-if="!hits.length"
                    message="No hits during this trip"
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
                            class="w-full flex items-center gap-2 px-4 py-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors"
                        >
                            <span class="w-1 self-stretch rounded-full bg-red-500 flex-shrink-0"></span>
                            <span class="font-mono font-semibold text-base">{{ group.plate }}</span>
                            <span class="ml-auto flex items-center gap-2">
                                <span class="text-sm font-medium px-2 py-0.5 rounded-full bg-red-100 text-red-700">
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
                                class="flex items-center gap-3 py-3 border-b border-gray-100 last:border-0 cursor-pointer"
                                @click="selectedHit = hit"
                            >
                                <img
                                    :src="hit.plate_image"
                                    alt="Plate"
                                    class="w-[80px] h-[48px] object-cover rounded border border-gray-200 flex-shrink-0"
                                    @load="$event.target.style.display = ''"
                                    @error="$event.target.style.display = 'none'"
                                />
                                <img
                                    :src="hit.vehicle_image"
                                    alt="Vehicle"
                                    class="w-[80px] h-[48px] object-cover rounded border border-gray-200 flex-shrink-0"
                                    @load="$event.target.style.display = ''"
                                    @error="$event.target.style.display = 'none'"
                                />
                                <span class="text-sm text-gray-500 ml-auto flex-shrink-0">{{ formatTime(hit.time) }}</span>
                            </div>
                        </div>
                    </div>
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
import { useTripsStore } from '../stores/trips';
import { useAuthStore } from '../stores/auth';
import { useMapStore } from '../stores/map';
import { useViewport } from '../composables/useViewport';
import FloatingStatCard from './FloatingStatCard.vue';
import SidePanel from './SidePanel.vue';
import BottomSheet from './BottomSheet.vue';
import EmptyState from './EmptyState.vue';
import ErrorState from './ErrorState.vue';
import { formatDate, formatTime, formatDuration } from '../lib/formatters';

const trips = useTripsStore();
const mapStore = useMapStore();
const authStore = useAuthStore();
// On compact the controls widget is hidden (so --scout-controls-h is unpublished);
// anchor the overlay column near the top instead of offsetting by that height.
const { isCompact } = useViewport();

const tripDetail = computed(() => trips.tripDetail);
const selectedHit = ref(null);
const panelOpen = ref(false);

// DEV-2052/DEV-2058: range-hit markers carry hit_type ('L' Direct Live, 'S' Staging
// Live) so the sheet can label which type was tapped. Trip markers carry no hit_type —
// fall back to nothing rather than guessing. Mirrors MapShell's mapping.
const hitTypeLabel = computed(() => ({
    L: 'Direct Live',
    S: 'Staging Live',
}[selectedHit.value?.type] ?? ''));

const hits = computed(() => tripDetail.value?.hits_detail ?? []);

const expandedPlates = ref(new Set());

const groupedHits = computed(() => {
    const map = new Map();
    for (const hit of hits.value) {
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

function reload() {
    const t = tripDetail.value;
    trips.tripDetail = null;
    if (t) trips.fetchTripDetail(t.date, t.trip_index);
}

// Reset transient UI when the loaded trip changes (ScoutHome owns the fetch).
watch(() => [trips.tripDetail?.date, trips.tripDetail?.trip_index], () => {
    selectedHit.value = null;
    panelOpen.value = false;
    expandedPlates.value = new Set();
});
</script>

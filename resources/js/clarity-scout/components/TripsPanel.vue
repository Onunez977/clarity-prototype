<template>
    <SidePanel :open="open" title="Trips" width="480px" @update:open="$emit('close')">
        <!-- Date range filter -->
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <input type="date" v-model="trips.calStartDate"
                   class="border border-gray-200 rounded-full px-3 py-1.5 text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 min-h-[40px]" />
            <span class="text-gray-400 text-sm">to</span>
            <input type="date" v-model="trips.calEndDate"
                   class="border border-gray-200 rounded-full px-3 py-1.5 text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 min-h-[40px]" />
            <button @click="trips.applyDateFilter()"
                    :disabled="!trips.calStartDate || !trips.calEndDate"
                    class="px-4 py-1.5 rounded-full text-sm font-medium text-white disabled:opacity-40 min-h-[40px] transition-opacity"
                    style="background-color: #FF6666">
                Apply
            </button>
            <button v-if="trips.calendarFilter"
                    @click="trips.clearCalendarFilter()"
                    class="px-3 py-1.5 rounded-full text-sm font-medium bg-gray-100 text-gray-500 hover:bg-gray-200 min-h-[40px]">
                Clear
            </button>
        </div>

        <!-- Error state -->
        <ErrorState
            v-if="trips.error"
            :message="trips.error"
            @retry="trips.fetchAll()"
            class="mb-3"
        />

        <!-- Loading skeleton -->
        <div v-if="trips.loading && !trips.groupedTripList.length" class="space-y-3">
            <div v-for="i in 6" :key="i"
                 class="bg-white rounded-2xl px-5 py-4 shadow-sm shadow-gray-200/60 min-h-[80px]">
                <div class="h-5 w-40 bg-gray-200 rounded animate-pulse mb-3"></div>
                <div class="h-4 w-64 bg-gray-200 rounded animate-pulse"></div>
            </div>
        </div>

        <!-- Empty state -->
        <EmptyState
            v-else-if="!trips.groupedTripList.length && !trips.loading"
            message="No trips found"
            icon="trips"
        />

        <!-- Day summary cards grouped by month -->
        <div v-else>
            <template v-for="(month, mIdx) in monthGroups" :key="month.key">
                <!-- Month separator -->
                <div v-if="mIdx > 0" class="flex items-center gap-3 my-5">
                    <div class="flex-1 h-px bg-gray-300/40"></div>
                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">{{ month.label }}</span>
                    <div class="flex-1 h-px bg-gray-300/40"></div>
                </div>

                <!-- Cards for this month -->
                <div class="space-y-3">
                    <button
                        v-for="dayGroup in month.days"
                        :key="dayGroup.date"
                        @click="goToDay(dayGroup)"
                        class="w-full bg-white rounded-2xl px-5 py-4 text-left shadow-sm shadow-gray-200/60 transition-shadow hover:shadow-md hover:shadow-gray-300/40 active:scale-[0.99] min-h-[80px]"
                    >
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ formatDateFriendly(dayGroup.date) }}
                            </h3>
                            <svg class="w-5 h-5 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </div>
                        <p class="text-sm text-gray-400 mt-2">
                            <span>{{ dayGroup.trips.length }} {{ dayGroup.trips.length === 1 ? 'trip' : 'trips' }}</span>
                            <span class="mx-1.5">&middot;</span>
                            <span>{{ formatDuration(dayGroup.totalDuration) }}</span>
                            <span class="mx-1.5">&middot;</span>
                            <span>{{ dayGroup.totalScans.toLocaleString() }} scans</span>
                            <span class="mx-1.5">&middot;</span>
                            <span :class="dayGroup.totalHits > 0 ? 'text-red-500 font-medium' : ''">{{ dayGroup.totalHits }} hits</span>
                            <span class="mx-1.5">&middot;</span>
                            <span>{{ Math.round(dayGroup.totalMiles * 10) / 10 }} mi</span>
                        </p>
                    </button>
                </div>
            </template>

            <!-- Infinite scroll sentinel -->
            <div v-if="!trips.calendarFilter" ref="sentinel" class="py-4 text-center">
                <div v-if="trips.loadingMore" class="flex items-center justify-center gap-2 text-gray-400 text-sm">
                    <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Loading more...
                </div>
                <p v-else-if="!trips.hasMore && trips.allTrips.length > 0" class="text-gray-400 text-sm">
                    No more trips
                </p>
            </div>
        </div>
    </SidePanel>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { useTripsStore } from '../stores/trips';
import SidePanel from './SidePanel.vue';
import EmptyState from './EmptyState.vue';
import ErrorState from './ErrorState.vue';
import { openDay } from '../lib/scoutNav';
import { formatDateFriendly, formatDuration } from '../lib/formatters';

const props = defineProps({
    open: { type: Boolean, default: false },
});
defineEmits(['close']);

const router = useRouter();
const trips = useTripsStore();

const sentinel = ref(null);
let observer = null;

const monthGroups = computed(() => {
    const groups = [];
    let current = null;

    for (const dayGroup of trips.groupedTripList) {
        const d = new Date(dayGroup.date + 'T00:00:00');
        const key = `${d.getFullYear()}-${d.getMonth()}`;
        const label = d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

        if (!current || current.key !== key) {
            current = { key, label, days: [] };
            groups.push(current);
        }
        current.days.push(dayGroup);
    }

    return groups;
});

function goToDay(dayGroup) {
    openDay(router, dayGroup.date);
}

function setupObserver() {
    if (observer) observer.disconnect();
    if (!sentinel.value) return;
    observer = new IntersectionObserver(
        ([entry]) => {
            if (entry.isIntersecting && !trips.calendarFilter) {
                trips.fetchMore();
            }
        },
        { rootMargin: '200px' }
    );
    observer.observe(sentinel.value);
}

watch(sentinel, (el) => {
    if (el) setupObserver();
});

// Lazily load trips the first time the panel opens.
watch(() => props.open, (isOpen) => {
    if (isOpen && !trips.allTrips.length && !trips.loading) {
        trips.fetchAll().then(() => nextTick(setupObserver));
    } else if (isOpen) {
        nextTick(setupObserver);
    }
}, { immediate: true });
</script>

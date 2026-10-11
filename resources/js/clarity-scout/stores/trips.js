import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { authHeaders, handleUnauthorized, buildUrl } from '../lib/fetch';

function todayString() {
    return new Date().toISOString().slice(0, 10);
}

function daysAgoString(n) {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return d.toISOString().slice(0, 10);
}

function dayBeforeString(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() - 1);
    return d.toISOString().slice(0, 10);
}

function daysBeforeString(dateStr, n) {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() - n);
    return d.toISOString().slice(0, 10);
}

export const useTripsStore = defineStore('trips', () => {
    const allTrips = ref([]);
    const oldestDateLoaded = ref(null);
    const hasMore = ref(true);
    const tripDetail = ref(null);
    const dayDetail = ref(null);
    const loading = ref(false);
    const loadingMore = ref(false);
    const error = ref(null);

    // Calendar filter state
    const calendarFilter = ref(null); // { start: 'YYYY-MM-DD', end: 'YYYY-MM-DD' } or null
    const calendarTrips = ref([]);
    const calStartDate = ref('');
    const calEndDate = ref('');

    const tripList = computed(() => {
        if (calendarFilter.value) return calendarTrips.value;
        return allTrips.value;
    });

    const groupedTripList = computed(() => {
        const list = tripList.value;
        if (!list.length) return [];

        const groups = {};
        for (const trip of list) {
            if (!groups[trip.date]) {
                groups[trip.date] = {
                    date: trip.date,
                    trips: [],
                    totalScans: 0,
                    totalHits: 0,
                    totalMiles: 0,
                    totalDuration: 0,
                    earliestStart: trip.started_at,
                    latestEnd: trip.ended_at,
                };
            }
            const g = groups[trip.date];
            g.trips.push(trip);
            g.totalScans += trip.scans;
            g.totalHits += trip.hits;
            g.totalMiles += trip.miles_driven;
            g.totalDuration += trip.active_duration_minutes;
            if (trip.started_at < g.earliestStart) g.earliestStart = trip.started_at;
            if (trip.ended_at > g.latestEnd) g.latestEnd = trip.ended_at;
        }

        return Object.values(groups);
    });

    async function apiFetch(path, params) {
        const response = await fetch(buildUrl(path, params), {
            headers: authHeaders(),
        });
        handleUnauthorized(response);
        if (!response.ok) throw new Error('Failed to load trips');
        return await response.json();
    }

    async function fetchInitial() {
        const endDate = todayString();
        const startDate = daysAgoString(30);
        const result = await apiFetch('/trips/browse', { start_date: startDate, end_date: endDate });
        allTrips.value = result.data;
        oldestDateLoaded.value = startDate;
        hasMore.value = result.meta.has_more;
    }

    async function fetchMore() {
        if (loadingMore.value || !hasMore.value || calendarFilter.value) return;
        loadingMore.value = true;
        const endDate = dayBeforeString(oldestDateLoaded.value);
        const startDate = daysBeforeString(endDate, 6); // 7 days inclusive
        try {
            const result = await apiFetch('/trips/browse', { start_date: startDate, end_date: endDate });
            allTrips.value = [...allTrips.value, ...result.data];
            oldestDateLoaded.value = startDate;
            hasMore.value = result.meta.has_more;
        } catch {
            // Silently fail — user can scroll again to retry
        } finally {
            loadingMore.value = false;
        }
    }

    async function fetchDateRange(start, end) {
        loading.value = true;
        error.value = null;
        calendarFilter.value = { start, end };
        try {
            const result = await apiFetch('/trips/browse', { start_date: start, end_date: end });
            calendarTrips.value = result.data;
        } catch (e) {
            error.value = e.message || 'Failed to load trips.';
        } finally {
            loading.value = false;
        }
    }

    function applyDateFilter() {
        if (!calStartDate.value || !calEndDate.value) return;
        fetchDateRange(calStartDate.value, calEndDate.value);
    }

    function clearCalendarFilter() {
        calendarFilter.value = null;
        calendarTrips.value = [];
        calStartDate.value = '';
        calEndDate.value = '';
    }

    async function fetchTripDetail(date, tripIndex) {
        loading.value = true;
        error.value = null;

        try {
            const response = await fetch(buildUrl(`/trips/${date}/${tripIndex}`), {
                headers: authHeaders(),
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to load trip detail');
            const result = await response.json();
            tripDetail.value = result.data;
        } catch (e) {
            error.value = e.message || 'Failed to load trip detail.';
        } finally {
            loading.value = false;
        }
    }

    async function fetchDayDetail(date) {
        loading.value = true;
        error.value = null;

        try {
            const response = await fetch(buildUrl(`/trips/day/${date}`), {
                headers: authHeaders(),
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to load day detail');
            const result = await response.json();
            dayDetail.value = result.data;
        } catch (e) {
            error.value = e.message || 'Failed to load day detail.';
        } finally {
            loading.value = false;
        }
    }

    async function fetchAll() {
        loading.value = true;
        error.value = null;

        try {
            await fetchInitial();
        } catch (e) {
            error.value = e.message || 'Failed to load trips.';
        } finally {
            loading.value = false;
        }
    }

    return {
        allTrips,
        tripList,
        groupedTripList,
        oldestDateLoaded,
        hasMore,
        tripDetail,
        dayDetail,
        loading,
        loadingMore,
        error,
        calendarFilter,
        calendarTrips,
        calStartDate,
        calEndDate,
        fetchInitial,
        fetchMore,
        fetchDateRange,
        applyDateFilter,
        clearCalendarFilter,
        fetchTripDetail,
        fetchDayDetail,
        fetchAll,
    };
});

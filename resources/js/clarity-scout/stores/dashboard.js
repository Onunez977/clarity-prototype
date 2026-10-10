import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { authHeaders, handleUnauthorized, buildUrl } from '../lib/fetch';
import { pooled } from '../lib/pooled';
import { useMapStore } from './map';

export const useDashboardStore = defineStore('dashboard', () => {
    const statsByRange = ref({});
    const recentHits = ref([]);
    const loading = ref(false);
    const refreshing = ref(false);
    const error = ref(null);
    const range = ref('today');

    const stats = computed(() => statsByRange.value[range.value] ?? null);

    async function fetchStats(targetRange = null) {
        const r = targetRange ?? range.value;
        const response = await fetch(buildUrl('/dashboard/stats', { range: r }), {
            headers: authHeaders(),
        });
        handleUnauthorized(response);
        if (!response.ok) throw new Error('Failed to load stats');
        const result = await response.json();
        statsByRange.value[r] = result.data;
    }

    async function fetchRecentHits(targetRange = null) {
        const response = await fetch(
            buildUrl('/dashboard/recent-hits', targetRange ? { range: targetRange } : {}),
            { headers: authHeaders() },
        );
        handleUnauthorized(response);
        if (!response.ok) throw new Error('Failed to load recent hits');
        const result = await response.json();
        // DEV-2059 F2: suppress any hit whose HIDE disposition was just saved but
        // hasn't landed in ClickHouse yet — shares the map store's hiddenScanIds
        // so the "Live Hits Pending Repossession" list doesn't flicker the same
        // hit back in before a later poll removes it again.
        recentHits.value = useMapStore().applyHiddenScanIdsFilter(result.data);
    }

    async function fetchAll() {
        loading.value = true;
        error.value = null;

        try {
            await Promise.all([
                fetchStats(range.value),
                // Both the driver and admin Live Hits lists are date-scoped and move
                // with the active filter (DEV-2137).
                fetchRecentHits(range.value),
            ]);
        } catch (e) {
            error.value = e.message || 'Failed to load dashboard data.';
        } finally {
            loading.value = false;
        }
    }

    async function setRange(newRange) {
        if (newRange === range.value) return;

        range.value = newRange;

        if (statsByRange.value[newRange]) {
            // Cached data exists — show it instantly, refresh in background
            refreshing.value = true;
            try {
                await Promise.all([fetchStats(newRange), fetchRecentHits(newRange)]);
            } catch {
                // Silently fail — stale data is still showing
            } finally {
                refreshing.value = false;
            }
        } else {
            // No cached data — show loading spinner
            loading.value = true;
            error.value = null;
            try {
                await Promise.all([fetchStats(newRange), fetchRecentHits(newRange)]);
            } catch (e) {
                error.value = e.message || 'Failed to load stats.';
            } finally {
                loading.value = false;
            }
        }
    }

    async function prefetchAllRanges(ranges = null) {
        // Callers may scope which ranges to warm (DEV-2186: drivers only warm
        // Today + 7d); null keeps the full set for admin and legacy callers.
        const allRanges = ranges ?? ['today', 'yesterday', 'last_7', 'last_15', 'last_30'];
        // Skip the current range and anything already cached client-side, then
        // fetch the rest a couple at a time rather than one serial chain (F2).
        const toFetch = allRanges.filter(r => r !== range.value && !statsByRange.value[r]);

        await pooled(toFetch, r => fetchStats(r), 2);
    }

    return {
        stats,
        statsByRange,
        recentHits,
        loading,
        refreshing,
        error,
        range,
        fetchStats,
        fetchRecentHits,
        fetchAll,
        setRange,
        prefetchAllRanges,
    };
});

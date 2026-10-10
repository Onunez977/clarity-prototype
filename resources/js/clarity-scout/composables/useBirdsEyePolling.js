import { onMounted, onUnmounted } from 'vue';
import { useMapStore } from '../stores/map';
import { useDashboardStore } from '../stores/dashboard';

// Bird's Eye live polling (DEV-2116) — the admin analogue of useKitPositionPolling.
// Self-gating: a no-op unless map.adminMode is set, so App.vue can call it
// unconditionally without affecting the driver app. On mount it seeds the driver
// list + first positions/trails/stats. Positions + trails then keep growing on
// every ~10s tick regardless of date range (DEV-2310); today's stats/hits only
// refresh in Live mode. Chained setTimeout (not setInterval) so a slow tick
// never overlaps the next; paused when the tab hides.
const POLL_MS = 10000;

export function useBirdsEyePolling() {
    const map = useMapStore();
    const dashboard = useDashboardStore();
    let timer = null;
    let stopped = false;

    // Admin never connects Echo, so useConnectionStatus' echo-derived isOffline is
    // spuriously true here — gate on navigator.onLine instead, or all admin polling
    // would be suppressed.
    function isOnline() {
        return typeof navigator === 'undefined' || navigator.onLine;
    }

    // Driver positions + trails persist across all date ranges (DEV-2310), so
    // they poll unconditionally; today's stats/hits stay Live-only.
    async function fetchPositions() {
        await Promise.all([map.fetchDriverPositions(), map.fetchDriverPaths()]);
    }

    async function fetchTodayStats() {
        await Promise.all([
            dashboard.fetchStats('today'),
            // Keep the Live Hits Pending Repossession list fresh at the
            // active scope (selection/followed driver/org-wide) without a
            // manual refresh (DEV-2179) — rides the same ~10s tick as the
            // stats; the endpoint's short cache coalesces concurrent tabs.
            dashboard.fetchRecentHits('today'),
        ]);
    }

    async function tick() {
        if (stopped) return;
        // Offline: skip the fetch body but keep the timer scheduled so polling
        // resumes automatically; the 'online' event fires an immediate catch-up.
        if (isOnline() && document.visibilityState === 'visible') {
            try {
                await fetchPositions();
                if (map.liveMode) await fetchTodayStats();
            } catch {
                // transient — keep polling
            }
        }
        if (!stopped) timer = setTimeout(tick, POLL_MS);
    }

    function onOnline() {
        // Reconnected — immediate catch-up instead of waiting a full POLL_MS.
        if (stopped || !isOnline()) return;
        if (document.visibilityState === 'visible') {
            fetchPositions().catch(() => {});
            if (map.liveMode) fetchTodayStats().catch(() => {});
        }
    }

    onMounted(async () => {
        if (!map.adminMode) return;
        try {
            // DEV-2293: seed the roster's active_in_range for the dashboard's
            // already-selected range so the selector opens pre-filtered.
            await map.fetchDrivers(dashboard.range);
            await Promise.all([map.fetchDriverPositions(), map.fetchDriverPaths()]);
        } catch {
            // initial load failures are non-fatal; the poll retries
        }
        window.addEventListener('online', onOnline);
        timer = setTimeout(tick, POLL_MS);
    });

    onUnmounted(() => {
        stopped = true;
        if (timer) clearTimeout(timer);
        window.removeEventListener('online', onOnline);
    });
}

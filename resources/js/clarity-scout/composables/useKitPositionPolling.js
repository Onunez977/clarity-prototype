import { onMounted, onBeforeUnmount } from 'vue';
import { useMapStore } from '../stores/map';
import { useDashboardStore } from '../stores/dashboard';

const POLL_MS = 10_000;
// Rebuild today's hit MARKERS every Nth tick (~2 min) rather than on every 10s
// poll — they're heavier to redraw and matched to /map/hits' 'today' cache TTL.
// The stat-bar counts refetch every tick (cheap, short-TTL) so they stay live.
const HITS_REFRESH_EVERY = 12;

/**
 * Polls /map/kit-position roughly every POLL_MS to keep the live dot fresh —
 * the kit's own GPS feed (sc_positions) is the sole source of the position.
 * Pauses when the tab is hidden (no point burning requests while off-screen)
 * and resumes on visibility.
 *
 * Uses chained setTimeout (not setInterval) so a slow CH response can't queue
 * up overlapping requests behind it. Each tick fires only after the previous
 * fetch settles — observed interval becomes max(POLL_MS, actual fetch time).
 */
export function useKitPositionPolling() {
    const mapStore = useMapStore();
    const dashboardStore = useDashboardStore();
    let timerId = null;
    let active = false;       // owned by the lifecycle (visible+mounted), not the timer
    let inFlight = false;
    let tickCount = 0;

    function isOnline() {
        return typeof navigator === 'undefined' || navigator.onLine;
    }

    async function tick() {
        if (inFlight) {
            // Should never hit this — schedule() doesn't fire while inFlight —
            // but cheap belt for safety.
            scheduleNext();
            return;
        }
        // Offline: skip the fetch body but keep the timer scheduled so polling
        // resumes automatically. The 'online' event also fires an immediate
        // catch-up so we don't wait a full POLL_MS after reconnecting.
        if (!isOnline()) {
            scheduleNext();
            return;
        }
        inFlight = true;
        try {
            // Advance both the live dot (kit-position) and the live trail
            // (live-path, drawn through intermediate sc_positions points) under
            // the same 10s cadence (DEV-2012).
            const tasks = [
                mapStore.fetchKitPosition(),
                mapStore.fetchLivePath(),
            ];
            // In live mode keep the Today stat tiles live by refetching the
            // authoritative full-day stats every tick (~10s) — the short 'today'
            // cache TTL keeps this near-live while coalescing concurrent ticks. The
            // heavier hit MARKERS still rebuild only every HITS_REFRESH_EVERY (~2 min).
            if (mapStore.liveMode) {
                tasks.push(dashboardStore.fetchStats('today'));
                // Keep the Live Hits Pending Repossession list fresh without a
                // manual refresh (DEV-2179) — same ~10s cadence as the stats.
                tasks.push(dashboardStore.fetchRecentHits('today'));
                if (++tickCount % HITS_REFRESH_EVERY === 0) {
                    tasks.push(mapStore.fetchRangeHits('today'));
                }
            }
            await Promise.all(tasks);
        } finally {
            inFlight = false;
            scheduleNext();
        }
    }

    function scheduleNext() {
        if (!active) return;
        timerId = setTimeout(tick, POLL_MS);
    }

    function start() {
        if (active) return;
        active = true;
        // Don't fire immediately on mount — the dashboard's first paint already
        // fetched once (via ScannerMap's onMounted). Schedule the first poll
        // POLL_MS from now.
        scheduleNext();
    }

    function stop() {
        active = false;
        if (timerId !== null) {
            clearTimeout(timerId);
            timerId = null;
        }
    }

    function catchUp() {
        if (!isOnline()) return;
        mapStore.fetchKitPosition();
        mapStore.fetchLivePath();
        if (mapStore.liveMode) {
            mapStore.fetchRangeHits('today');
            // Refresh the tiles + Live Hits list immediately rather than waiting a
            // tick (DEV-2179).
            dashboardStore.fetchStats('today');
            dashboardStore.fetchRecentHits('today');
        }
    }

    function onVisibilityChange() {
        if (document.visibilityState === 'visible') {
            // Immediate catch-up fetch when coming back, then resume the loop.
            catchUp();
            start();
        } else {
            stop();
        }
    }

    function onOnline() {
        // Reconnected — fire an immediate catch-up (mirrors the visibility
        // catch-up) instead of waiting for the next scheduled tick. Only while
        // visible; a hidden tab has stopped the loop and resumes on visibility.
        if (document.visibilityState === 'visible') {
            catchUp();
        }
    }

    onMounted(() => {
        start();
        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('online', onOnline);
    });

    onBeforeUnmount(() => {
        stop();
        document.removeEventListener('visibilitychange', onVisibilityChange);
        window.removeEventListener('online', onOnline);
    });
}

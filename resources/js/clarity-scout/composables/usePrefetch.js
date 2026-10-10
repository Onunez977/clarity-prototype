import { driverPrefetchRanges } from '../lib/formatters';

let scheduled = false;

export function usePrefetch() {
    // DEV-2185: no longer background-prefetches scan PATHS for any range
    // (mapStore.prefetchAllRangePaths, dropped) — that pulled the full un-bboxed
    // 15/30/60-day payload ~2s after mount, defeating the scan-path zoom/bbox
    // gate. Stats-only prefetch remains, scoped per DEV-2186 below.
    function scheduleRangePrefetch(dashboardStore, mapStore = null) {
        if (scheduled) return;
        scheduled = true;

        // Drivers now show all 5 pills (DEV-2306) but deliberately still only
        // warm Today/Yesterday/7d (DEV-2186) — 15/30 stay heavy and fetch
        // on-demand when tapped. Admin (Bird's Eye) and any caller without a
        // map store keep the full-range stat prefetch (ranges = null).
        const ranges = mapStore && !mapStore.adminMode ? driverPrefetchRanges : null;

        const doPrefetch = () => {
            dashboardStore.prefetchAllRanges(ranges);
        };

        if ('requestIdleCallback' in window) {
            requestIdleCallback(doPrefetch, { timeout: 5000 });
        } else {
            setTimeout(doPrefetch, 2000);
        }
    }

    return { scheduleRangePrefetch };
}

import { computed } from 'vue';
import { echoState } from '../lib/echo';
import { useConnectionStatus } from './useConnectionStatus';
import { useMapStore } from '../stores/map';

// Realtime (Echo) connection signal for the Scout chrome — the dot color + the
// "sustained offline in Live mode" flag. Shared by the desktop controls widget and
// the mobile Profile button so the status logic lives in one place.
export function useEchoStatus() {
    const mapStore = useMapStore();
    const { isOffline } = useConnectionStatus();

    // Gate the stale visual on the canonical live-mode flag so it never lights up in
    // Historical mode (matches the offline banner + stat bar).
    const showStale = computed(() => isOffline.value && mapStore.liveMode);

    const echoBucket = computed(() => {
        switch (echoState.value) {
            case 'connected':
                return 'connected';
            case 'connecting':
            case 'initialized':
            case 'unavailable':
                return 'connecting';
            case 'failed':
            case 'disconnected':
            default:
                return 'disconnected';
        }
    });

    const echoDotClass = computed(() => {
        switch (echoBucket.value) {
            case 'connected':
                return 'bg-emerald-500';
            case 'connecting':
                return 'bg-amber-500';
            case 'disconnected':
            default:
                return 'bg-red-500';
        }
    });

    return { echoState, echoDotClass, showStale };
}

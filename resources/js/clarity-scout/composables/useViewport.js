import { ref, onMounted, onUnmounted } from 'vue';

// Tailwind `lg`. At/above this the driver app shows its full chrome; below it
// (phones + tablet-portrait, both orientations) the map overlays default to
// compact chips so the map is unobstructed. Tunable during QA.
const COMPACT_BELOW = '(min-width: 1024px)';

// Reactive breakpoint signal for the Scout map overlays. `isCompact` is true
// below the `lg` threshold. Initialised synchronously so the first paint already
// reflects the device width (overlays open collapsed on phones without a flash).
export function useViewport() {
    const mql = window.matchMedia(COMPACT_BELOW);
    const isCompact = ref(!mql.matches);

    function onChange(event) {
        isCompact.value = !event.matches;
    }

    onMounted(() => mql.addEventListener('change', onChange));
    onUnmounted(() => mql.removeEventListener('change', onChange));

    return { isCompact };
}

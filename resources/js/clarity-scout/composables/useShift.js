import { ref, computed } from 'vue';
import { authHeaders, buildUrl, handleUnauthorized } from '../lib/fetch';

const STATUS_PATH = '/shift/status';
const END_PATH = '/shift/end';

// Lazy module-scoped state — shared across all composable callers in the
// same page load. Cheap, and keeps the End Shift button + any future shift
// indicators in sync without prop drilling.
//
// Default `open` to `false` so the End Shift button does NOT flash visible
// before fetchStatus() returns. The button only appears once the API
// confirms an active shift exists.
const shiftId = ref(null);
const open = ref(false);
const startedAt = ref(null);
const endedAt = ref(null);
const endType = ref(null);
const loading = ref(false);
const error = ref(null);
const lastLoadedAt = ref(0);
// Shared so the End Shift trigger (the floating pill in ScoutHome) and the
// confirmation modal (App.vue, kept at root so it layers above the stat bar)
// stay in sync without prop drilling across the stacking-context boundary.
const confirmEndShift = ref(false);

export function useShift() {
    const shiftEnded = computed(() => shiftId.value !== null && open.value === false);

    async function fetchStatus({ force = false } = {}) {
        // De-dup: avoid hammering /shift/status if a component remounts.
        if (!force && Date.now() - lastLoadedAt.value < 5000) {
            return;
        }

        loading.value = true;
        error.value = null;

        try {
            const response = await fetch(buildUrl(STATUS_PATH), {
                method: 'GET',
                headers: authHeaders(),
            });

            if (!response.ok) {
                handleUnauthorized(response);
                throw new Error(`Failed to load shift status (${response.status})`);
            }

            const { data } = await response.json();
            shiftId.value = data?.shift_id ?? null;
            open.value = data?.open ?? true;
            startedAt.value = data?.started_at ?? null;
            endedAt.value = data?.ended_at ?? null;
            endType.value = data?.end_type ?? null;
            lastLoadedAt.value = Date.now();
        } catch (e) {
            error.value = e?.message || 'Failed to load shift status';
        } finally {
            loading.value = false;
        }
    }

    async function endShift() {
        loading.value = true;
        error.value = null;

        try {
            const response = await fetch(buildUrl(END_PATH), {
                method: 'POST',
                headers: authHeaders(),
            });

            if (!response.ok) {
                handleUnauthorized(response);
                throw new Error(`Failed to end shift (${response.status})`);
            }

            const { data } = await response.json();
            shiftId.value = data?.shift_id ?? shiftId.value;
            open.value = false;
            startedAt.value = data?.started_at ?? startedAt.value;
            endedAt.value = data?.ended_at ?? new Date().toISOString();
            endType.value = data?.end_type ?? 'confirmed';
            lastLoadedAt.value = Date.now();

            return data;
        } catch (e) {
            error.value = e?.message || 'Failed to end shift';
            throw e;
        } finally {
            loading.value = false;
        }
    }

    return {
        shiftId,
        open,
        shiftEnded,
        startedAt,
        endedAt,
        endType,
        loading,
        error,
        confirmEndShift,
        fetchStatus,
        endShift,
    };
}

import { ref } from 'vue';

// Bird's Eye admin scope (DEV-2116). The driver selector sets the chosen kit_ids
// here; `buildUrl` (lib/fetch.js) auto-appends them to every admin request, so
// the existing Scout store fetches (scan-paths, hits, stats, driver-positions…)
// all scope to the selection with no per-action plumbing. null = everyone.
//
// Kept in its own tiny module to avoid a fetch.js <-> map-store import cycle.
export const selectedKitIds = ref(null);

export function setSelectedKitIds(ids) {
    selectedKitIds.value = (ids && ids.length) ? [...ids] : null;
}

// The followed/"highlighted" driver. When set, the STATS box scopes to just this
// driver (buildUrl applies it on the /dashboard/stats request); other layers keep
// the selection scope. null = not following → stats use the selection.
export const followedKitId = ref(null);

export function setFollowedKitId(kitId) {
    followedKitId.value = kitId ?? null;
}

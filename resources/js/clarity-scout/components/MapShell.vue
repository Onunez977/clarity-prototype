<template>
    <!-- The single persistent map canvas. Mounted once while authenticated and
         never torn down on navigation; every page renders as an overlay on top
         of it. No z-index here so ScannerMap's own controls (My Location) keep
         their stacking relative to the overlays above. -->
    <div class="fixed inset-0">
        <ScannerMap />
        <!-- Driver-only live tracking: gated off admin Bird's Eye (DEV-2179) so the
             driver kit-position poller (useKitPositionPolling → /map/kit-position,
             /map/live-path) doesn't mount org-wide, where those routes don't exist
             and would 200-text/html + double-poll today's stats. -->
        <LiveTracking v-if="mapStore.viewMode === 'dashboard' && !mapStore.adminMode" />

        <!-- DEV-2016: hit-details sheet. It lives on the persistent map host (not in
             an overlay) so it shows for BOTH Recent Hits taps on the dashboard and
             map-marker taps in the trip/day views, which swap the overlay out. Hit
             shapes differ by source: Recent Hits carries hit_time + status; trip
             markers carry time and no status — render defensively.
             DEV-2594: z-[45] so the sheet stacks above ScoutHome chrome (Live Hits
             z-[26], controls z-[25], App bottom cluster z-40) — otherwise an open
             Live Hits card on compact can hide the sheet and the tap looks dead. -->
        <div class="relative z-[45]">
            <BottomSheet
                :open="!!mapStore.selectedHit"
                :content-key="hitSheetContentKey"
                title="Hit Details"
                offsetClass="bottom-8"
                @update:open="mapStore.clearSelectedHit()"
            >
            <!-- Condensed header: plate + type pill + timestamp replace the
                 "Hit Details" title to keep the sheet short (DEV-2058 follow-up). -->
            <template #header>
                <div v-if="mapStore.selectedHit" class="flex items-center gap-2 min-w-0">
                    <span class="text-lg font-bold text-gray-900 truncate">{{ mapStore.selectedHit.plate || '—' }}</span>
                    <span
                        v-if="hitTypeLabel"
                        class="flex-shrink-0 text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full"
                        :class="mapStore.selectedHit.hit_type === 'S'
                            ? 'bg-amber-100 text-amber-700'
                            : 'bg-blue-100 text-blue-700'"
                    >{{ hitTypeLabel }}</span>
                    <span v-if="hitTime" class="flex-shrink-0 text-xs text-gray-400">{{ hitTime }}</span>
                </div>
            </template>

            <!-- Fixed content width so the sheet doesn't resize as you cycle between
                 hits with differently-sized images (DEV-2016). -->
            <div v-if="mapStore.selectedHit" class="flex flex-col gap-3 pb-2 w-[620px] max-w-[84vw]">
                <!-- DEV-2058: vehicle info row (all fields nullable). Mirrors the
                     trip/day overlay sheets so the dashboard hit modal matches. -->
                <p
                    v-if="vehicleLabel || mapStore.selectedHit.hotlist_us_state"
                    class="text-sm text-gray-600"
                >{{ vehicleLabel }}<span
                        v-if="mapStore.selectedHit.hotlist_us_state"
                        class="text-gray-400"
                    >{{ vehicleLabel ? ' · ' : '' }}{{ mapStore.selectedHit.hotlist_us_state }}</span></p>

                <div
                    v-if="mapStore.selectedHit.plate_image || mapStore.selectedHit.vehicle_image"
                    class="flex gap-2"
                >
                    <button
                        v-if="mapStore.selectedHit.plate_image"
                        type="button"
                        class="flex-1 min-h-[44px] min-w-[44px]"
                        aria-label="Enlarge plate image"
                        @click="enlargeScanImage(mapStore.selectedHit.plate_image, 'Plate')"
                    >
                        <img
                            :src="mapStore.selectedHit.plate_image"
                            alt="Plate"
                            class="h-48 w-full rounded-xl object-contain bg-gray-100"
                        />
                    </button>
                    <button
                        v-if="mapStore.selectedHit.vehicle_image"
                        type="button"
                        class="flex-1 min-h-[44px] min-w-[44px]"
                        aria-label="Enlarge vehicle image"
                        @click="enlargeScanImage(mapStore.selectedHit.vehicle_image, 'Vehicle')"
                    >
                        <img
                            :src="mapStore.selectedHit.vehicle_image"
                            alt="Vehicle"
                            class="h-48 w-full rounded-xl object-cover bg-gray-100"
                        />
                    </button>
                </div>

                <!-- DEV-2059: driver-set disposition — only for hits that carry a
                     scan_id (map markers / recent hits, wired up via the store);
                     trip/day markers carry none and stay read-only. Mirrors the
                     admin disposition-popup Blade component's two-step flow:
                     picking "Invalid Scan" reveals reason buttons instead of
                     saving immediately. -->
                <div
                    v-if="mapStore.selectedHit.scan_id"
                    class="flex flex-col gap-2 pt-2 border-t border-gray-100"
                >
                    <label class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Disposition</label>
                    <!-- DEV-2059: an in-flow dropdown (not a native <select>) —
                         mirrors DriverSelector's "Options dropdown (in-flow so it
                         never clips)" pattern. A native select's popup list opened
                         off the bottom of this bottom sheet on mobile; rendering the
                         options as normal DOM content lets the sheet's own
                         overflow-y-auto content area scroll to include all of them. -->
                    <div class="relative">
                        <button
                            type="button"
                            :disabled="savingDisposition"
                            @click="dispositionMenuOpen = !dispositionMenuOpen"
                            class="w-full min-h-[44px] flex items-center justify-between gap-2 rounded-xl border bg-white px-3 text-sm disabled:opacity-50"
                            :class="selectedDisposition ? 'border-gray-200 text-gray-700' : 'border-red-500 text-gray-400'"
                            aria-haspopup="listbox"
                            :aria-expanded="dispositionMenuOpen"
                            aria-label="Set disposition"
                        >
                            <span class="truncate">{{ selectedDispositionLabel }}</span>
                            <svg
                                class="w-4 h-4 text-gray-400 flex-shrink-0 transition-transform duration-200"
                                :class="{ 'rotate-180': dispositionMenuOpen }"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <div
                            v-if="dispositionMenuOpen"
                            role="listbox"
                            class="mt-1.5 rounded-xl border border-gray-200 bg-white shadow-lg overflow-hidden"
                        >
                            <button
                                v-for="opt in DISPOSITION_OPTIONS"
                                :key="opt.value"
                                type="button"
                                role="option"
                                :aria-selected="selectedDisposition === opt.value"
                                :disabled="savingDisposition"
                                @click="selectDisposition(opt.value)"
                                class="w-full flex items-center px-3 py-2.5 text-sm text-left hover:bg-gray-50 min-h-[44px] disabled:opacity-50"
                                :class="selectedDisposition === opt.value ? 'text-gray-900 font-semibold' : 'text-gray-600'"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="showReasons" class="grid grid-cols-2 gap-2">
                        <button
                            v-for="reason in REASON_OPTIONS"
                            :key="reason.value"
                            type="button"
                            :disabled="savingDisposition"
                            @click="submitInvalidScan(reason.value)"
                            class="min-h-[44px] rounded-xl border border-gray-200 bg-white px-2 text-xs font-medium text-gray-700 disabled:opacity-50"
                        >
                            {{ reason.label }}
                        </button>
                    </div>
                </div>

                <!-- DEV-2016: cluster cycling — only when a cluster (>1 hit) is open.
                     Prev/Next wrap around; the select jumps straight to a timestamp. -->
                <div v-if="mapStore.selectedHits.length > 1" class="flex flex-col gap-2 pt-2 border-t border-gray-100">
                    <div class="flex items-center justify-between gap-2">
                        <button
                            type="button"
                            @click="mapStore.prevHit()"
                            class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-xl bg-gray-100 text-gray-700 text-xl font-bold leading-none"
                            aria-label="Previous hit"
                        >‹</button>
                        <span class="text-xs font-semibold text-gray-500 tabular-nums">
                            {{ mapStore.selectedHitIndex + 1 }} of {{ mapStore.selectedHits.length }}
                        </span>
                        <button
                            type="button"
                            @click="mapStore.nextHit()"
                            class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-xl bg-gray-100 text-gray-700 text-xl font-bold leading-none"
                            aria-label="Next hit"
                        >›</button>
                    </div>
                    <select
                        :value="mapStore.selectedHitIndex"
                        @change="mapStore.selectHitAt(Number($event.target.value))"
                        class="w-full min-h-[44px] rounded-xl border border-gray-200 bg-white px-3 text-sm text-gray-700"
                        aria-label="Jump to a hit"
                    >
                        <option v-for="(h, i) in mapStore.selectedHits" :key="i" :value="i">
                            {{ hitOptionLabel(h) }}
                        </option>
                    </select>
                </div>
            </div>
        </BottomSheet>
        </div>

        <!-- DEV-2599: sibling of the z-[45] sheet, teleported to body at z-[60]. -->
        <ScanImageLightbox
            :open="enlargedSrc !== ''"
            :src="enlargedSrc"
            :alt="enlargedAlt"
            @close="closeEnlargedScanImage"
        />
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useMapStore } from '../stores/map';
import ScannerMap from './ScannerMap.vue';
import LiveTracking from './LiveTracking.vue';
import BottomSheet from './BottomSheet.vue';
import ScanImageLightbox from './ScanImageLightbox.vue';
import { useToast } from '../composables/useToast';

const mapStore = useMapStore();
const { show: showToast } = useToast();

// DEV-2594: identity of the open hit so BottomSheet can re-animate when the
// selection changes while open stays true (list row → another row, or marker).
const hitSheetContentKey = computed(() => {
    const hit = mapStore.selectedHit;
    if (!hit) {
        return null;
    }
    return hit.scan_id ?? hit.id ?? hit.plate ?? 'hit';
});

// DEV-2599: local only. Enlarge must not call focusHit, clearSelectedHit,
// setRange, or toggle the Live Hits list. A new hit identity closes it.
const enlargedSrc = ref('');
const enlargedAlt = ref('');

function enlargeScanImage(src, alt) {
    enlargedSrc.value = src;
    enlargedAlt.value = alt;
}

function closeEnlargedScanImage() {
    enlargedSrc.value = '';
    enlargedAlt.value = '';
}

watch(hitSheetContentKey, () => {
    closeEnlargedScanImage();
});

// DEV-2059: mirrors App\Enums\DispositionEnum::formOptions(missedOpportunity:
// false) — there's no enum->JS bridge for the Scout PWA, so the option list
// (value/label pairs, since Title === value for every case) is kept in sync
// here by hand. Order matches the enum's own alphabetical sort.
const DISPOSITION_OPTIONS = [
    { value: 'Accessible', label: 'Accessible' },
    { value: 'Inaccessible', label: 'Inaccessible' },
    { value: 'Invalid Scan', label: 'Invalid Scan' },
    { value: 'Moving', label: 'Moving' },
    { value: 'New Hit', label: 'New Hit' },
];

// Mirrors ScanDispositionRequest's `reason` validation list.
const REASON_OPTIONS = [
    { value: 'other', label: 'Other' },
    { value: 'not a plate', label: 'Not a plate' },
    { value: 'plate too blurry', label: 'Plate too blurry' },
    { value: 'wrong ocr', label: 'Wrong OCR' },
    { value: 'wrong plate state', label: 'Wrong plate state' },
];

const INVALID_SCAN_DISPOSITION = 'Invalid Scan';

const selectedDisposition = ref('');
const showReasons = ref(false);
const savingDisposition = ref(false);
const dispositionMenuOpen = ref(false);

const selectedDispositionLabel = computed(() => {
    return DISPOSITION_OPTIONS.find((opt) => opt.value === selectedDisposition.value)?.label ?? 'Select a Disposition';
});

// A new hit landed in the sheet (opened fresh, cycled via Prev/Next/jump) —
// seed the control from the hit's OWN saved disposition (DEV-2059 F1), like a
// normal form re-opening with its saved value, rather than always resetting to
// the placeholder. null/absent (New Hit) falls back to '' — the placeholder.
// A KEEP-disposition save (DEV-2059) refreshes the SAME hit (by scan_id) in
// place rather than removing it, so that case is skipped — reseeding here
// would otherwise blank the control right after the driver just picked a value.
watch(() => mapStore.selectedHit, (hit, prevHit) => {
    dispositionMenuOpen.value = false;
    if (hit?.scan_id != null && hit.scan_id === prevHit?.scan_id) {
        return;
    }
    selectedDisposition.value = hit?.disposition ?? '';
    showReasons.value = false;
});

function selectDisposition(value) {
    dispositionMenuOpen.value = false;
    onDispositionChange(value);
}

function onDispositionChange(value) {
    selectedDisposition.value = value;
    if (value === INVALID_SCAN_DISPOSITION) {
        // Two-step, mirroring the admin disposition-popup Blade component:
        // reveal reason buttons instead of saving immediately.
        showReasons.value = true;
        return;
    }
    showReasons.value = false;
    saveDisposition(value, null);
}

function submitInvalidScan(reason) {
    saveDisposition(INVALID_SCAN_DISPOSITION, reason);
}

async function saveDisposition(disposition, reason) {
    const scanId = mapStore.selectedHit?.scan_id;
    if (!scanId || savingDisposition.value) {
        return;
    }

    savingDisposition.value = true;
    try {
        // updateSelectedHitDisposition resolves true on success and false on
        // failure (it surfaces its own error toast + keeps the hit visible), so
        // only toast success here — never treat "no throw" as saved.
        const ok = await mapStore.updateSelectedHitDisposition(scanId, disposition, reason);
        if (ok) {
            showToast('Disposition saved', { type: 'success', duration: 2000 });
        }
    } finally {
        savingDisposition.value = false;
        showReasons.value = false;
    }
}

// Recent Hits rows carry `hit_time`; trip-marker / range hits carry `time`. Format
// whichever is present into the same short label the Recent Hits list uses.
function formatHitTime(hit) {
    const iso = hit?.hit_time ?? hit?.time;
    if (! iso) {
        return '';
    }
    try {
        return new Date(iso).toLocaleString([], {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
        });
    } catch {
        return '';
    }
}

const hitTime = computed(() => formatHitTime(mapStore.selectedHit));

// DEV-2052: range-hit markers carry hit_type ('L' Direct Live, 'S' Staging Live)
// so the modal can label which type was tapped. Trip markers carry no hit_type —
// fall back to nothing rather than guessing.
const HIT_TYPE_LABELS = { L: 'Direct Live', S: 'Staging Live' };

const hitTypeLabel = computed(() => HIT_TYPE_LABELS[mapStore.selectedHit?.hit_type] ?? '');

// Year/make/model joined with real spaces (template whitespace gets trimmed,
// which collapsed "2018 Chevrolet Traverse" into one word). State stays separate
// so it keeps its own muted styling.
const vehicleLabel = computed(() => {
    const hit = mapStore.selectedHit;
    return [hit?.vehicle_year, hit?.vehicle_make, hit?.vehicle_model].filter(Boolean).join(' ');
});

// Label for the cluster quick-jump <select>: "time — plate · type".
function hitOptionLabel(hit) {
    const time = formatHitTime(hit);
    const plate = hit?.plate || 'N/A';
    const type = HIT_TYPE_LABELS[hit?.hit_type];
    const base = time ? `${time} — ${plate}` : plate;
    return type ? `${base} · ${type}` : base;
}

</script>

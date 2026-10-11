<template>
    <!-- Card + header mirror the Map Layers widget. -->
    <div ref="root" class="relative z-20 bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge p-4 w-[280px] pointer-events-auto">
        <button class="w-full flex items-center gap-2 min-h-[44px] text-left" @click="expanded = !expanded">
            <span class="flex-1 text-xs font-bold text-gray-400 uppercase tracking-wider">Drivers</span>
            <svg
                class="w-5 h-5 text-gray-400 transition-transform duration-200 flex-shrink-0"
                :class="{ 'rotate-180': expanded }"
                fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div v-show="expanded" class="mt-3">
            <!-- Combobox: selected drivers as chips + an inline search input. -->
            <div
                class="rounded-xl border-2 transition-colors cursor-text"
                :class="open ? 'border-[#FF6666]' : 'border-gray-200'"
                @click="focusInput"
            >
                <div class="flex flex-wrap items-center gap-1.5 p-2">
                    <span
                        v-for="d in selectedList"
                        :key="d.kit_id"
                        class="inline-flex items-center gap-1 pl-2 pr-1 py-1 rounded-lg bg-[#FFF0F0] text-[#D84315] text-xs font-semibold max-w-full"
                    >
                        <span class="truncate">{{ label(d) }}</span>
                        <button type="button" class="shrink-0 rounded hover:bg-[#FFD9D9] p-0.5" @click.stop="toggle(d.kit_id)" :aria-label="`Remove ${label(d)}`">
                            <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 10-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                        </button>
                    </span>
                    <input
                        ref="input"
                        v-model="search"
                        type="text"
                        :placeholder="selectedList.length ? 'Add a driver…' : 'All drivers — search to filter…'"
                        class="flex-1 min-w-[90px] py-1 px-1 text-sm bg-transparent outline-none placeholder:text-gray-400"
                        @focus="open = true"
                        @keydown.esc="open = false"
                    />
                </div>
            </div>

            <!-- Options dropdown (in-flow so it never clips). -->
            <div v-show="open" class="mt-1.5 rounded-xl border border-gray-200 bg-white shadow-lg overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                        {{ options.length }} driver{{ options.length === 1 ? '' : 's' }}
                    </span>
                    <button type="button" class="text-xs font-semibold text-[#FF6666] hover:text-[#e05555]" @click="hasSelection ? clearAll() : selectAll()">
                        {{ hasSelection ? 'Clear' : 'Select all' }}
                    </button>
                </div>
                <div class="max-h-56 overflow-y-auto py-1">
                    <button
                        v-for="d in options"
                        :key="d.kit_id"
                        type="button"
                        class="w-full flex items-center gap-2 px-3 py-2.5 text-sm text-left hover:bg-gray-50 min-h-[40px]"
                        :class="[
                            isChecked(d.kit_id) ? 'text-gray-900 font-semibold' : 'text-gray-600',
                            d.active_in_range === true ? '' : 'opacity-50',
                        ]"
                        @click="toggle(d.kit_id)"
                    >
                        <span class="w-4 flex-shrink-0">
                            <svg v-if="isChecked(d.kit_id)" class="w-4 h-4 text-[#FF6666]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.3 3.29 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                        </span>
                        <span class="truncate flex-1">{{ label(d) }}</span>
                        <span v-if="d.active_in_range !== true" class="shrink-0 text-[10px] font-medium text-gray-400 whitespace-nowrap">inactive in this range</span>
                    </button>
                    <p v-if="!options.length && search.trim()" class="px-3 py-3 text-sm text-gray-400">No drivers match "{{ search }}".</p>
                    <p v-else-if="!options.length" class="px-3 py-3 text-sm text-gray-400">No drivers have scanned in this time range.</p>
                </div>
            </div>

            <!-- How the picker works (DEV-2293): the list is scan-activity-scoped,
                 the map's live dots are a separate feed — one line so the admin isn't
                 confused by seeing more drivers on the map than in the list. -->
            <p class="mt-2 text-[11px] leading-relaxed text-gray-400">
                Lists drivers who scanned in the selected time range; the map still shows everyone reporting a live location.
            </p>

            <!-- Name-plate toggle -->
            <div class="flex items-center justify-between pt-3 mt-3 border-t border-gray-100">
                <span class="text-sm font-semibold text-gray-600">Driver name plates</span>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="store.driverNameplatesEnabled"
                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0"
                    :class="store.driverNameplatesEnabled ? 'bg-[#FF6666]' : 'bg-gray-300'"
                    @click="store.toggleDriverNameplates()"
                >
                    <span
                        class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform"
                        :class="store.driverNameplatesEnabled ? 'translate-x-5' : 'translate-x-0.5'"
                    ></span>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useMapStore } from '../stores/map';
import { selectedKitIds } from '../lib/adminScope';

const store = useMapStore();
const search = ref('');
const open = ref(false);
const root = ref(null);
const input = ref(null);

// Collapsible like Map Layers; default collapsed, persisted.
const EXPANDED_KEY = 'scanner_org_map_drivers_expanded';
const expanded = ref(localStorage.getItem(EXPANDED_KEY) === '1');
watch(expanded, (o) => {
    try {
        localStorage.setItem(EXPANDED_KEY, o ? '1' : '0');
    } catch { /* private mode */ }
    if (!o) open.value = false;
});

// Empty selection = no filter = everyone on the map; chips show only what's been picked.
const localSel = ref(new Set(selectedKitIds.value ?? []));

const hasSelection = computed(() => localSel.value.size > 0 && localSel.value.size < store.drivers.length);
const noFilter = computed(() => localSel.value.size === 0 || localSel.value.size === store.drivers.length);

// Selected/followed chips resolve from the FULL roster regardless of
// active_in_range — a selected driver who goes idle stays labelled, not dropped.
const selectedList = computed(() => store.drivers.filter((d) => localSel.value.has(d.kit_id)));

// DEV-2293: default visible options are drivers active in the selected range;
// searching also surfaces inactive matches (rendered dimmed, see template) so a
// driver isn't unreachable just because they haven't scanned in this window.
const activeDrivers = computed(() => store.drivers.filter((d) => d.active_in_range === true));
const options = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) {
        return activeDrivers.value;
    }
    const matches = (d) => label(d).toLowerCase().includes(q);
    const inactiveMatches = store.drivers.filter((d) => d.active_in_range !== true && matches(d));
    return [...activeDrivers.value.filter(matches), ...inactiveMatches];
});

function label(driver) {
    return driver.driver_name || driver.name || `Kit ${driver.kit_id}`;
}

function isChecked(kitId) {
    return localSel.value.has(kitId);
}

function focusInput() {
    open.value = true;
    nextTick(() => input.value?.focus());
}

function toggle(kitId) {
    const next = new Set(localSel.value);
    next.has(kitId) ? next.delete(kitId) : next.add(kitId);
    localSel.value = next;
    scheduleApply();
}

// "Select all" selects the drivers currently LISTED (the range-active options, or
// the search matches when searching) — not the full roster. Selecting kits that
// aren't shown in the dropdown was a bug (DEV-2293): on a filtered range it added
// every kit as a chip while the dropdown only listed the active ones.
function selectAll() {
    localSel.value = new Set(options.value.map((d) => d.kit_id));
    scheduleApply();
}

function clearAll() {
    localSel.value = new Set();
    scheduleApply();
}

// Debounced refetch — each change hits ~5 endpoints; coalesce bursts.
let applyTimer = null;
function scheduleApply() {
    clearTimeout(applyTimer);
    applyTimer = setTimeout(() => {
        store.setSelectedKits(noFilter.value ? null : [...localSel.value]);
    }, 300);
}

// Close the dropdown on an outside click.
function onWindowClick(e) {
    if (root.value && !root.value.contains(e.target)) open.value = false;
}
onMounted(() => window.addEventListener('click', onWindowClick));
onBeforeUnmount(() => window.removeEventListener('click', onWindowClick));
</script>

<template>
    <div
        data-otto-widget="search"
        :data-widget="type"
        :data-search-id="searchId"
        class="otto-widget not-prose my-3 w-full overflow-hidden rounded-lg border shadow-sm"
        :class="shellClass"
    >
        <div class="flex items-center gap-2 border-b px-3 py-2" :class="headerBorderClass">
            <span class="h-2 w-2 rounded-full" :class="dotClass"></span>
            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ headerTitle }}</span>
            <span v-if="loaded && items.length > 0" class="ml-auto text-[10px] text-gray-400 dark:text-gray-500">{{ pageLabel }}</span>
        </div>
        <div v-if="loaded && items.length > 0">
            <table class="w-full text-xs">
                <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                    <tr>
                        <th v-if="!isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Plate</th>
                        <th v-if="isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Name</th>
                        <th v-if="isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">No-scan</th>
                        <th v-if="isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Assigned drivers</th>
                        <th v-if="isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">ZIPs</th>
                        <th v-if="!isHotlist && !isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Time</th>
                        <th v-if="!isHotlist && !isZone" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Kit / Driver</th>
                        <th v-if="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                        <th v-if="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">VIN</th>
                        <th v-if="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Vehicle</th>
                        <th v-if="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Client</th>
                        <th v-if="isHotlist" class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Source</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.type + '-' + item.id"
                        class="cursor-pointer border-t border-gray-100 transition hover:bg-primary-50 dark:border-gray-800 dark:hover:bg-primary-900/20"
                        @mouseenter="highlight(item, $event)"
                        @mouseleave="clearHighlight"
                        @click="showOnMap(item)"
                    >
                        <td v-if="!isZone" class="px-3 py-1.5 font-mono font-semibold text-gray-800 dark:text-gray-200">{{ item.plate }}</td>
                        <td v-if="isZone" class="px-3 py-1.5 font-semibold text-gray-800 dark:text-gray-200">{{ item.name || item.plate }}</td>
                        <td v-if="isZone" class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ item.is_no_scan_zone ? 'No-scan' : '—' }}</td>
                        <td v-if="isZone" class="max-w-[10rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ zoneDrivers(item) }}</td>
                        <td v-if="isZone" class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ zoneZipLabel(item) }}</td>
                        <td v-if="!isHotlist && !isZone" class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ item.scanned_at }}</td>
                        <td v-if="!isHotlist && !isZone" class="max-w-[10rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ item.camera || '—' }}</td>
                        <td v-if="isHotlist" class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ item.status_label || '—' }}</td>
                        <td v-if="isHotlist" class="px-3 py-1.5 font-mono text-gray-500 dark:text-gray-400">{{ item.vin || '—' }}</td>
                        <td v-if="isHotlist" class="max-w-[10rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ item.vehicle || '—' }}</td>
                        <td v-if="isHotlist" class="max-w-[10rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ item.client || '—' }}</td>
                        <td v-if="isHotlist" class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ item.source || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="!loaded" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
            Loading results…
        </div>
        <div v-else-if="items.length === 0" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
            No results found.
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { investigation } from '../../lib/investigation.js';
import { matchesSearchId } from '../../lib/format.js';

const props = defineProps({
    type: { type: String, default: 'scan' },
    searchId: { type: String, required: true },
    title: { type: String, default: '' },
});

const loaded = ref(false);
const items = ref([]);
const resolvedTitle = ref(props.title);

const isHit = computed(() => props.type === 'hit');
const isHotlist = computed(() => props.type === 'hotlist');
const isZone = computed(() => props.type === 'zone');

const headerTitle = computed(() => {
    return resolvedTitle.value
        || (isZone.value ? 'Zone Results' : (isHotlist.value ? 'Assignment Results' : (isHit.value ? 'Hit Results' : 'Scan Results')));
});

const pageLabel = computed(() => {
    const n = items.value.length;
    if (n < 1) {
        return '';
    }

    return n === 1 ? '1 most recent' : n + ' most recent';
});

const shellClass = computed(() => {
    if (isZone.value) {
        return 'bg-violet-50 dark:bg-violet-900/10 border-violet-200 dark:border-violet-800';
    }
    if (isHotlist.value) {
        return 'bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-800';
    }
    if (isHit.value) {
        return 'bg-red-50 dark:bg-red-900/10 border-red-200 dark:border-red-800';
    }

    return 'bg-blue-50 dark:bg-blue-900/10 border-blue-200 dark:border-blue-800';
});

const headerBorderClass = computed(() => {
    if (isZone.value) {
        return 'bg-violet-50 dark:bg-violet-900/10 border-violet-200 dark:border-violet-800';
    }
    if (isHotlist.value) {
        return 'bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-800';
    }
    if (isHit.value) {
        return 'bg-red-50 dark:bg-red-900/10 border-red-200 dark:border-red-800';
    }

    return 'bg-blue-50 dark:bg-blue-900/10 border-blue-200 dark:border-blue-800';
});

const dotClass = computed(() => {
    if (isZone.value) {
        return 'bg-violet-500';
    }
    if (isHotlist.value) {
        return 'bg-amber-500';
    }
    if (isHit.value) {
        return 'bg-red-500';
    }

    return 'bg-blue-500';
});

function pool() {
    if (isZone.value) {
        return investigation.zones;
    }
    if (isHotlist.value) {
        return investigation.hotlists;
    }
    if (isHit.value) {
        return investigation.hits;
    }

    return investigation.scans;
}

function loadItems() {
    items.value = pool().filter((i) => matchesSearchId(i, props.searchId));
    const search = investigation.searches.find((s) => s.searchId === props.searchId);
    if (search?.title) {
        resolvedTitle.value = search.title;
    }
    if (search || items.value.length > 0) {
        loaded.value = true;
    }
}

function highlight(item, event) {
    window.dispatchEvent(new CustomEvent('otto-highlight', { detail: item }));
    if (isHotlist.value || isZone.value) {
        return;
    }
    window.dispatchEvent(new CustomEvent('otto-show-detail', { detail: { item, event } }));
}

function clearHighlight() {
    window.dispatchEvent(new CustomEvent('otto-clear-highlight'));
    window.dispatchEvent(new CustomEvent('otto-hide-detail'));
}

function showOnMap(item) {
    if (isHotlist.value) {
        return;
    }
    if (isZone.value) {
        const pane = document.querySelector('[data-otto-results-root]');
        if (pane && item.lat && item.lng) {
            window.dispatchEvent(new CustomEvent('otto-pan-to', { detail: item }));

            return;
        }
        if (item.editor_url) {
            window.location.assign(item.editor_url);
        }

        return;
    }
    window.dispatchEvent(new CustomEvent('otto-pan-to', { detail: item }));
}

function zoneDrivers(item) {
    const names = item.assigned_drivers || [];

    return names.length ? names.join(', ') : '—';
}

function zoneZipLabel(item) {
    const n = Number(item.zip_count || 0);
    if (n < 1) {
        return '—';
    }

    return n === 1 ? '1 ZIP' : n + ' ZIPs';
}

watch(() => [investigation.loaded, investigation.scans, investigation.hits, investigation.hotlists, investigation.zones, investigation.searches], loadItems, { immediate: true, deep: true });
</script>

<template>
    <div
        data-otto-results-root
        class="flex h-full min-h-0 flex-col overflow-hidden bg-white dark:bg-gray-900"
    >
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2.5 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Investigation Results</h3>
        </div>

        <div v-if="hasData" class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-4 py-2 dark:border-gray-700">
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-medium transition"
                :class="typeChip('scan')"
                @click="toggleType('scan')"
            >
                <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                Scans
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-medium transition"
                :class="typeChip('hit')"
                @click="toggleType('hit')"
            >
                <span class="h-2 w-2 rounded-full bg-red-500"></span>
                Hits
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-medium transition"
                :class="typeChip('scout_driver')"
                @click="toggleType('scout_driver')"
            >
                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                Drivers
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-medium transition"
                :class="typeChip('zone')"
                @click="toggleType('zone')"
            >
                <span class="h-2 w-2 rounded-full bg-violet-500"></span>
                Zones
            </button>

            <div class="relative ml-auto flex items-center">
                <svg class="pointer-events-none absolute left-2.5 h-3.5 w-3.5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input
                    v-model="plateFilter"
                    type="text"
                    placeholder="Search plate, driver, or kit..."
                    class="w-52 rounded-lg border border-gray-300 bg-white py-1.5 pl-8 pr-2.5 text-xs text-gray-700 placeholder-gray-400 transition focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300"
                >
            </div>
        </div>

        <div class="relative h-[50vh] w-full shrink-0">
            <div ref="mapContainer" class="absolute inset-0"></div>
            <div
                v-if="loading && !hasData"
                class="absolute inset-0 z-[1] flex items-center justify-center bg-white/80 dark:bg-gray-900/80"
            >
                <div class="flex items-center gap-2 text-xs text-gray-400">
                    <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading results…
                </div>
            </div>
            <div
                v-if="!loading && !hasMapPoints"
                class="absolute inset-0 z-[1] flex items-center justify-center bg-white/80 dark:bg-gray-900/80"
            >
                <p class="text-xs text-gray-400 dark:text-gray-500">No search results yet</p>
            </div>
        </div>

        <div v-if="hasData" class="max-h-20 overflow-y-auto border-b border-t border-gray-200 px-4 py-2 dark:border-gray-700">
            <p class="mb-1 text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500">Searches</p>
            <div class="flex flex-wrap gap-x-3 gap-y-1">
                <span v-for="search in searches" :key="search.searchId" class="inline-flex items-center gap-1.5 whitespace-nowrap py-0.5 text-xs">
                    <button
                        type="button"
                        class="h-3 w-3 shrink-0 cursor-pointer rounded-full transition-opacity"
                        :class="[
                            search.searchType === 'hit' ? 'bg-red-500' : (search.searchType === 'scout_driver' ? 'bg-emerald-500' : (search.searchType === 'zone' ? 'bg-violet-500' : 'bg-blue-500')),
                            activeSearchIds.includes(search.searchId) ? 'opacity-100' : 'opacity-25',
                        ]"
                        :title="activeSearchIds.includes(search.searchId) ? 'Hide this search' : 'Show this search'"
                        @click.stop="toggleSearchVisibility(search.searchId)"
                    ></button>
                    <button
                        type="button"
                        class="cursor-pointer transition hover:text-gray-900 dark:hover:text-gray-100"
                        :class="activeSearchIds.includes(search.searchId) ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 dark:text-gray-500 line-through'"
                        title="Show only this search"
                        @click.stop="isolateSearch(search.searchId)"
                    >{{ search.title || search.searchType + ' search' }}</button>
                </span>
            </div>
        </div>

        <div v-if="hasData" class="min-h-0 flex-1 overflow-y-auto">
            <table class="w-full text-xs">
                <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Plate / Driver</th>
                        <th class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Time</th>
                        <th class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Kit / Camera</th>
                        <th class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Location</th>
                        <th class="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in filteredItems"
                        :key="item.type + '-' + item.id"
                        :data-otto-item="item.type + '-' + item.id"
                        data-otto-hoverable
                        class="cursor-pointer border-t border-gray-100 transition hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50"
                        :class="highlightedItemId === (item.type + '-' + item.id) ? '!bg-primary-100 dark:!bg-primary-900/30' : ''"
                        @click="panTo(item)"
                        @mouseenter="onRowEnter(item, $event)"
                        @mouseleave="onRowLeave"
                    >
                        <td
                            class="px-3 py-1.5 text-gray-800 dark:text-gray-200"
                            :class="item.type === 'scout_driver' || item.type === 'zone' ? 'font-medium' : 'font-mono'"
                        >{{ rowLabel(item) }}</td>
                        <td class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ rowTime(item) }}</td>
                        <td class="max-w-[8rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ rowKit(item) }}</td>
                        <td class="max-w-[14rem] truncate px-3 py-1.5 text-gray-500 dark:text-gray-400" :title="rowLocation(item)">{{ rowLocation(item) }}</td>
                        <td class="px-3 py-1.5">
                            <span
                                class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium"
                                :class="typeBadge(item.type)"
                            >{{ typeLabel(item.type) }}</span>
                        </td>
                    </tr>
                    <tr v-if="filteredItems.length === 0">
                        <td colspan="5" class="px-3 py-4 text-center text-xs text-gray-400 dark:text-gray-500">No results match filters</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { investigation, loadInvestigation } from './lib/investigation.js';
import { driverSeenAt, fmtRelative, fmtTime } from './lib/format.js';
import { makePopupOverlay, pinColor, pinIcon, popupHtml, zoneRings } from './lib/map-popup.js';

const props = defineProps({
    conversationId: { type: String, default: null },
});

const mapContainer = ref(null);
const loading = ref(true);
const plateFilter = ref('');
const activeTypes = ref(['scan', 'hit', 'scout_driver', 'zone']);
const activeSearchIds = ref([]);
const highlightedItemId = ref(null);

let map = null;
let popupOverlay = null;
let markers = [];
let resizeObserver = null;
let mapRetry = null;
let historyOpen = false;
let popupsHeld = false;

function popupsBlocked() {
    return historyOpen || popupsHeld;
}

const scans = computed(() => investigation.scans);
const hits = computed(() => investigation.hits);
const drivers = computed(() => investigation.drivers);
const zones = computed(() => investigation.zones);
const searches = computed(() => investigation.searches);

const allItems = computed(() => [...scans.value, ...hits.value, ...drivers.value, ...zones.value]);
const hasData = computed(() => allItems.value.length > 0);
const hasMapPoints = computed(() => scans.value.length > 0 || hits.value.length > 0 || drivers.value.length > 0 || zones.value.length > 0);

const filteredItems = computed(() => {
    const seen = new Set();
    const plate = (plateFilter.value || '').toUpperCase();

    return allItems.value.filter((item) => {
        if (!activeTypes.value.includes(item.type)) {
            return false;
        }
        const ids = item.searchIds || (item.searchId ? [item.searchId] : []);
        if (!ids.some((id) => activeSearchIds.value.includes(id))) {
            return false;
        }
        if (plate && !matchesFilter(item, plate)) {
            return false;
        }
        const key = item.type + '-' + item.id;
        if (seen.has(key)) {
            return false;
        }
        seen.add(key);

        return true;
    });
});

function matchesFilter(item, needle) {
    const haystack = [
        item.plate,
        item.name,
        item.driver_name,
        item.kit_name,
        item.camera,
        item.place,
        ...(item.assigned_drivers || []),
    ]
        .filter(Boolean)
        .join(' ')
        .toUpperCase();

    return haystack.includes(needle);
}

function rowLabel(item) {
    if (item.type === 'zone') {
        return item.name || item.plate || 'Zone';
    }
    if (item.type === 'scout_driver') {
        return item.driver_name || item.kit_name || item.plate || 'Driver';
    }

    return item.plate || '';
}

function rowKit(item) {
    if (item.type === 'zone') {
        const names = item.assigned_drivers || [];

        return names.length ? names.join(', ') : '—';
    }
    if (item.type === 'scout_driver') {
        return item.kit_name || item.camera || '—';
    }

    return item.camera || '—';
}

function rowLocation(item) {
    if (item.type === 'zone') {
        const n = Number(item.zip_count || 0);
        if (n < 1) {
            return '—';
        }

        return n === 1 ? '1 ZIP' : n + ' ZIPs';
    }
    if (item.type === 'scout_driver') {
        return item.place || '—';
    }

    return '—';
}

function rowTime(item) {
    if (item.type === 'scout_driver') {
        return fmtRelative(driverSeenAt(item));
    }
    if (item.type === 'zone') {
        return '—';
    }

    return fmtTime(item.scanned_at);
}

function typeChip(type) {
    const on = activeTypes.value.includes(type);
    const map = {
        scan: on
            ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-blue-300 dark:border-blue-700'
            : 'bg-gray-50 dark:bg-gray-800 text-gray-400 border-gray-200 dark:border-gray-700',
        hit: on
            ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 border-red-300 dark:border-red-700'
            : 'bg-gray-50 dark:bg-gray-800 text-gray-400 border-gray-200 dark:border-gray-700',
        scout_driver: on
            ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700'
            : 'bg-gray-50 dark:bg-gray-800 text-gray-400 border-gray-200 dark:border-gray-700',
        zone: on
            ? 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 border-violet-300 dark:border-violet-700'
            : 'bg-gray-50 dark:bg-gray-800 text-gray-400 border-gray-200 dark:border-gray-700',
    };

    return map[type];
}

function typeBadge(type) {
    if (type === 'hit') {
        return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300';
    }
    if (type === 'scout_driver') {
        return 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300';
    }
    if (type === 'zone') {
        return 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300';
    }

    return 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300';
}

function typeLabel(type) {
    if (type === 'hit') {
        return 'Hit';
    }
    if (type === 'scout_driver') {
        return 'Driver';
    }
    if (type === 'zone') {
        return 'Zone';
    }

    return 'Scan';
}

function toggleType(type) {
    const idx = activeTypes.value.indexOf(type);
    if (idx > -1) {
        activeTypes.value.splice(idx, 1);
    } else {
        activeTypes.value.push(type);
    }
    renderMarkers();
}

function toggleSearchVisibility(searchId) {
    const idx = activeSearchIds.value.indexOf(searchId);
    if (idx > -1) {
        activeSearchIds.value.splice(idx, 1);
    } else {
        activeSearchIds.value.push(searchId);
    }
    renderMarkers();
}

function isolateSearch(searchId) {
    const allIds = searches.value.map((s) => s.searchId);
    const isAlreadySolo = activeSearchIds.value.length === 1 && activeSearchIds.value[0] === searchId;
    activeSearchIds.value = isAlreadySolo ? [...allIds] : [searchId];
    renderMarkers();
}

function mappable(items) {
    return items
        .map((item) => ({
            ...item,
            lat: Number(item.lat),
            lng: Number(item.lng),
        }))
        .filter((item) => Number.isFinite(item.lat) && Number.isFinite(item.lng) && item.lat !== 0 && item.lng !== 0);
}

function clearMarkers() {
    markers.forEach((m) => m.setMap(null));
    markers = [];
}

function extendBoundsWithGeometry(bounds, geometry) {
    let added = false;
    zoneRings(geometry).forEach((ring) => {
        ring.forEach((pair) => {
            if (!Array.isArray(pair) || pair.length < 2) {
                return;
            }
            const lng = Number(pair[0]);
            const lat = Number(pair[1]);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return;
            }
            bounds.extend({ lat, lng });
            added = true;
        });
    });

    return added;
}

function addPin(item) {
    const color = pinColor(item);
    const marker = new window.google.maps.Marker({
        position: { lat: item.lat, lng: item.lng },
        map,
        icon: pinIcon(window.google, color),
        title: rowLabel(item),
    });
    marker._ottoItem = item;
    marker.addListener('click', () => openPopup(item, marker));
    markers.push(marker);
}

function addZoneShape(item) {
    const color = pinColor(item);
    const rings = zoneRings(item.geometry);
    if (rings.length === 0) {
        addPin(item);

        return;
    }

    rings.forEach((ring) => {
        const path = ring
            .filter((pair) => Array.isArray(pair) && pair.length >= 2)
            .map(([lng, lat]) => ({ lat: Number(lat), lng: Number(lng) }))
            .filter((pt) => Number.isFinite(pt.lat) && Number.isFinite(pt.lng));
        if (path.length < 3) {
            return;
        }

        const polygon = new window.google.maps.Polygon({
            paths: path,
            strokeColor: color,
            strokeWeight: 2,
            strokeOpacity: 0.9,
            fillColor: color,
            fillOpacity: 0.25,
            clickable: true,
            editable: false,
            draggable: false,
            map,
        });
        polygon._ottoItem = item;
        polygon.addListener('click', () => openPopup(item));
        markers.push(polygon);
    });
}

function renderMarkers() {
    clearMarkers();
    if (!map) {
        return;
    }

    filteredItems.value.forEach((item) => {
        if (item.type === 'zone') {
            addZoneShape({
                ...item,
                lat: Number(item.lat),
                lng: Number(item.lng),
            });

            return;
        }

        const point = mappable([item])[0];
        if (point) {
            addPin(point);
        }
    });
}

function fitBounds() {
    if (!map) {
        return;
    }

    const bounds = new window.google.maps.LatLngBounds();
    let any = false;

    filteredItems.value.forEach((item) => {
        if (extendBoundsWithGeometry(bounds, item.geometry)) {
            any = true;
        }
        const lat = Number(item.lat);
        const lng = Number(item.lng);
        if (Number.isFinite(lat) && Number.isFinite(lng) && lat !== 0 && lng !== 0) {
            bounds.extend({ lat, lng });
            any = true;
        }
    });

    if (!any) {
        return;
    }

    map.fitBounds(bounds, 40);
}

function closePopup() {
    if (!popupOverlay) {
        return;
    }
    popupOverlay.setMap(null);
    popupOverlay = null;
}

function openPopup(item, marker) {
    if (popupsBlocked()) {
        return;
    }
    if (!map || !window.google?.maps?.OverlayView) {
        return;
    }

    const position = typeof marker?.getPosition === 'function'
        ? marker.getPosition()
        : null;
    const lat = position ? position.lat() : Number(item.lat);
    const lng = position ? position.lng() : Number(item.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return;
    }

    closePopup();
    popupOverlay = makePopupOverlay(
        window.google,
        new window.google.maps.LatLng(lat, lng),
        popupHtml(item),
        closePopup,
    );
    popupOverlay.setMap(map);
}

function panTo(item) {
    if (!map || !item) {
        return;
    }

    if (item.type === 'zone') {
        const bounds = new window.google.maps.LatLngBounds();
        if (extendBoundsWithGeometry(bounds, item.geometry) && !bounds.isEmpty()) {
            map.fitBounds(bounds, 40);
            openPopup(item);

            return;
        }
    }

    if (!item.lat || !item.lng) {
        return;
    }

    const lat = Number(item.lat);
    const lng = Number(item.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return;
    }

    map.panTo({ lat, lng });
    map.setZoom(15);

    let marker = markers.find((m) => m._ottoItem && m._ottoItem.type === item.type && m._ottoItem.id === item.id);
    if (!marker) {
        marker = markers.find((m) => {
            const pos = typeof m.getPosition === 'function' ? m.getPosition() : null;

            return pos && Math.abs(pos.lat() - lat) < 0.0001 && Math.abs(pos.lng() - lng) < 0.0001;
        });
    }
    if (marker) {
        openPopup(item, marker);
    }
}

function resizeMap() {
    if (!map) {
        return;
    }
    window.google.maps.event.trigger(map, 'resize');
    fitBounds();
}

function initMap() {
    if (!window.google?.maps) {
        mapRetry = setTimeout(() => initMap(), 400);

        return;
    }

    const el = mapContainer.value;
    if (!el) {
        mapRetry = setTimeout(() => initMap(), 50);

        return;
    }

    if (map) {
        renderMarkers();
        resizeMap();

        return;
    }

    if (el.firstChild) {
        el.replaceChildren();
    }

    const points = mappable(filteredItems.value);
    const center = points.length > 0
        ? { lat: points[0].lat, lng: points[0].lng }
        : { lat: 39.8283, lng: -98.5795 };

    map = new window.google.maps.Map(el, {
        center,
        zoom: points.length > 1 ? 4 : 13,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: true,
        gestureHandling: 'greedy',
    });
    map.addListener('click', () => closePopup());

    resizeObserver?.disconnect();
    resizeObserver = new ResizeObserver(() => resizeMap());
    resizeObserver.observe(el);

    renderMarkers();
    fitBounds();
    setTimeout(() => resizeMap(), 200);
}

async function fetchData() {
    if (!props.conversationId) {
        loading.value = false;

        return;
    }

    loading.value = true;
    await loadInvestigation(props.conversationId);
    activeSearchIds.value = searches.value.map((s) => s.searchId);
    loading.value = false;
    nextTick(() => initMap());
}

function highlightItem(item) {
    highlightedItemId.value = item.type + '-' + item.id;
    nextTick(() => {
        const row = document.querySelector('[data-otto-item="' + highlightedItemId.value + '"]');
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });
}

function onRowEnter(item, event) {
    if (popupsBlocked()) {
        return;
    }
    highlightItem(item);
    if (item.type !== 'scout_driver' && item.type !== 'zone') {
        window.dispatchEvent(new CustomEvent('otto-show-detail', { detail: { item, event } }));
    }
}

function onRowLeave() {
    highlightedItemId.value = null;
    window.dispatchEvent(new CustomEvent('otto-hide-detail'));
}

function onHighlight(e) {
    if (e.detail) {
        highlightItem(e.detail);
    }
}

function onClearHighlight() {
    highlightedItemId.value = null;
}

function onPanTo(e) {
    panTo(e.detail);
}

function onChanged() {
    fetchData();
}

function onDismissPopups() {
    popupsHeld = true;
    closePopup();
}

function onResumePopups() {
    popupsHeld = false;
    closePopup();
}

function onHistoryOpen(e) {
    historyOpen = e.detail === true;
    if (historyOpen) {
        closePopup();
        highlightedItemId.value = null;
    }
}

onMounted(() => {
    fetchData();
    window.addEventListener('otto-investigation-changed', onChanged);
    window.addEventListener('otto-highlight', onHighlight);
    window.addEventListener('otto-clear-highlight', onClearHighlight);
    window.addEventListener('otto-pan-to', onPanTo);
    window.addEventListener('otto-resize-map', resizeMap);
    window.addEventListener('otto-history-open', onHistoryOpen);
    window.addEventListener('otto-dismiss-popups', onDismissPopups);
    window.addEventListener('otto-popups-resume', onResumePopups);
});

onBeforeUnmount(() => {
    window.removeEventListener('otto-investigation-changed', onChanged);
    window.removeEventListener('otto-highlight', onHighlight);
    window.removeEventListener('otto-clear-highlight', onClearHighlight);
    window.removeEventListener('otto-pan-to', onPanTo);
    window.removeEventListener('otto-resize-map', resizeMap);
    window.removeEventListener('otto-history-open', onHistoryOpen);
    window.removeEventListener('otto-dismiss-popups', onDismissPopups);
    window.removeEventListener('otto-popups-resume', onResumePopups);
    resizeObserver?.disconnect();
    if (mapRetry) {
        clearTimeout(mapRetry);
    }
    closePopup();
    clearMarkers();
});

watch(() => props.conversationId, () => fetchData());
watch(filteredItems, () => {
    if (map) {
        renderMarkers();
    }
});
watch(searches, (list) => {
    if (activeSearchIds.value.length === 0) {
        activeSearchIds.value = list.map((s) => s.searchId);
    }
});
</script>

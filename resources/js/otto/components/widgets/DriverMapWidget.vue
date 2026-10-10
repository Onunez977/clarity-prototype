<template>
    <div
        data-otto-widget="driver-map"
        :data-widget="type"
        :data-search-id="searchId"
        class="otto-widget not-prose my-3 w-full overflow-hidden rounded-lg border border-emerald-200 bg-emerald-50 shadow-sm dark:border-emerald-800 dark:bg-emerald-900/10"
    >
        <div class="flex items-center gap-2 border-b border-emerald-200 px-3 py-2 dark:border-emerald-800">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ headerTitle }}</span>
            <span v-if="loaded" class="ml-auto text-[10px] text-gray-400 dark:text-gray-500">
                {{ items.length }} {{ items.length === 1 ? 'driver' : 'drivers' }}
            </span>
        </div>
        <div v-if="loaded && items.length > 0">
            <div class="relative h-40 overflow-hidden bg-gray-200 dark:bg-gray-800">
                <div ref="miniMap" class="absolute inset-0"></div>
                <div
                    class="absolute inset-0 z-10"
                    :class="isFullscreen ? 'cursor-default' : 'cursor-pointer'"
                    :role="isFullscreen ? 'img' : 'button'"
                    :tabindex="isFullscreen ? -1 : 0"
                    :aria-label="isFullscreen ? 'Driver location map' : 'Open full screen map'"
                    @click="openFullscreenIfNeeded"
                    @keydown.enter.prevent="openFullscreenIfNeeded"
                    @keydown.space.prevent="openFullscreenIfNeeded"
                ></div>
            </div>
            <ul class="divide-y divide-emerald-100 dark:divide-emerald-900/40">
                <li v-for="item in items" :key="item.type + '-' + item.id" class="px-3 py-2">
                    <div class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ rowLabel(item) }}</div>
                    <div class="mt-0.5 flex flex-wrap gap-x-2 text-[11px] text-gray-500 dark:text-gray-400">
                        <span v-if="rowKit(item)">{{ rowKit(item) }}</span>
                        <span v-if="rowPlace(item)">{{ rowPlace(item) }}</span>
                        <span v-if="rowWhen(item)">{{ rowWhen(item) }}</span>
                    </div>
                </li>
            </ul>
        </div>
        <div v-if="!loaded" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
            Loading location…
        </div>
        <div v-else-if="items.length === 0" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
            No driver locations found.
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { investigation } from '../../lib/investigation.js';
import { driverSeenAt, fmtRelative, matchesSearchId } from '../../lib/format.js';
import { pinIcon } from '../../lib/map-popup.js';

const props = defineProps({
    type: { type: String, default: 'scout_driver' },
    searchId: { type: String, required: true },
    title: { type: String, default: '' },
});

const miniMap = ref(null);
const loaded = ref(false);
const mapReady = ref(false);
const isFullscreen = ref(window._ottoFullscreen === true);
const items = ref([]);
const resolvedTitle = ref(props.title);

const headerTitle = computed(() => resolvedTitle.value || 'Scout driver location');
const points = computed(() => items.value.filter((i) => Number(i.lat) && Number(i.lng)));

let map = null;
let markers = [];
let retry = null;
let retryCount = 0;

function onFullscreen(e) {
    isFullscreen.value = e.detail === true;
}

function loadItems() {
    items.value = investigation.drivers.filter((i) => matchesSearchId(i, props.searchId));
    const search = investigation.searches.find((s) => s.searchId === props.searchId);
    if (search?.title) {
        resolvedTitle.value = search.title;
    }
    if (search || items.value.length > 0) {
        loaded.value = true;
    }
    initMap();
}

function rowLabel(item) {
    return item.driver_name || item.kit_name || item.plate || 'Driver';
}

function rowKit(item) {
    return item.kit_name || item.camera || '';
}

function rowPlace(item) {
    return item.place || '';
}

function rowWhen(item) {
    return fmtRelative(driverSeenAt(item));
}

function openFullscreenIfNeeded() {
    if (isFullscreen.value) {
        return;
    }
    window.dispatchEvent(new Event('otto-open-fullscreen'));
}

function clearMarkers() {
    markers.forEach((m) => m.setMap(null));
    markers = [];
}

function renderMarkers() {
    clearMarkers();
    if (!map || !window.google?.maps) {
        return;
    }
    const icon = pinIcon(window.google, '#10b981');
    points.value.forEach((item) => {
        markers.push(new window.google.maps.Marker({
            position: { lat: Number(item.lat), lng: Number(item.lng) },
            map,
            icon,
            clickable: false,
        }));
    });
}

function fit() {
    const pts = points.value;
    if (!map || !pts.length) {
        return;
    }
    if (pts.length === 1) {
        map.setCenter({ lat: Number(pts[0].lat), lng: Number(pts[0].lng) });
        map.setZoom(14);

        return;
    }

    const bounds = new window.google.maps.LatLngBounds();
    pts.forEach((p) => bounds.extend({ lat: Number(p.lat), lng: Number(p.lng) }));
    map.fitBounds(bounds, 24);
}

function initMap() {
    const pts = points.value;
    if (!pts.length) {
        return;
    }
    if (!window.google?.maps?.Map) {
        if (retryCount >= 25) {
            return;
        }
        retryCount += 1;
        retry = setTimeout(() => initMap(), 400);

        return;
    }

    const el = miniMap.value;
    if (!el) {
        if (retryCount >= 25) {
            return;
        }
        retryCount += 1;
        retry = setTimeout(() => initMap(), 50);

        return;
    }

    if (map) {
        renderMarkers();
        fit();
        mapReady.value = true;

        return;
    }

    const center = { lat: Number(pts[0].lat), lng: Number(pts[0].lng) };
    map = new window.google.maps.Map(el, {
        center,
        zoom: pts.length > 1 ? 10 : 14,
        disableDefaultUI: true,
        gestureHandling: 'none',
        keyboardShortcuts: false,
        clickableIcons: false,
        draggable: false,
        scrollwheel: false,
        disableDoubleClickZoom: true,
    });
    renderMarkers();
    fit();
    mapReady.value = true;
    window.google.maps.event.addListenerOnce(map, 'idle', () => {
        window.google.maps.event.trigger(map, 'resize');
        fit();
    });
}

onMounted(() => {
    window.addEventListener('otto-fullscreen-changed', onFullscreen);
    loadItems();
});

onBeforeUnmount(() => {
    window.removeEventListener('otto-fullscreen-changed', onFullscreen);
    if (retry) {
        clearTimeout(retry);
    }
    clearMarkers();
    map = null;
});

watch(() => [investigation.loaded, investigation.drivers, investigation.searches], loadItems, { deep: true });
</script>

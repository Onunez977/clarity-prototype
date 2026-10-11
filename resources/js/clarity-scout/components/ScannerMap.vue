<template>
    <div class="relative">
        <!-- Path / heatmap loading — path chunk progress wins when active so
             Org Map historical loads show how many kit groups remain. -->
        <div
            v-if="scanPathsProgress.active || loading"
            class="absolute top-12 left-1/2 -translate-x-1/2 z-10"
        >
            <div class="bg-white/95 shadow text-gray-700 text-xs px-3 py-2 rounded-xl min-w-[11rem]">
                <div class="flex items-center gap-2">
                    <svg class="animate-spin w-3 h-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span v-if="scanPathsProgress.active">
                        Loading paths {{ scanPathsProgress.done }}/{{ scanPathsProgress.total }}
                    </span>
                    <span v-else>Loading...</span>
                </div>
                <div
                    v-if="scanPathsProgress.active && scanPathsProgress.total > 0"
                    class="mt-1.5 h-1 w-full rounded-full bg-gray-200 overflow-hidden"
                >
                    <div
                        class="h-full rounded-full bg-slate-800 transition-[width] duration-200 ease-out"
                        :style="{ width: `${scanPathsProgressPercent}%` }"
                    ></div>
                </div>
            </div>
        </div>

        <!-- DEV-2314: the "zoom in to view path history" hint moved out of this
             map overlay into the bottom sheet (App.vue, above the date range
             filters) so users actually see it. Driven by mapStore.showZoomHint. -->

        <!-- Map Container — the single persistent full-screen canvas. -->
        <div ref="mapContainer" class="h-screen w-screen overflow-hidden"></div>

        <!-- The My Location button now lives in the bottom bar (App.vue) so it
             shares that container instead of overlapping it; it dispatches a
             recenter request this component consumes via the watcher below. -->
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Loader } from '@googlemaps/js-api-loader';
import { MarkerClusterer } from '@googlemaps/markerclusterer';
import { GoogleMapsOverlay as DeckOverlay } from '@deck.gl/google-maps';
import { PathLayer, GeoJsonLayer, ScatterplotLayer, TextLayer } from '@deck.gl/layers';
import { FillStyleExtension } from '@deck.gl/extensions';
import { useMapStore, metersBetween, SEGMENT_BREAK_METERS } from '../stores/map';
import { useToast } from '../composables/useToast';
import { scoutTrack } from '../lib/track';
import { SCOUT_EVENT, SCOUT_PAGE } from '../lib/analyticsEvents';
import {
    VEHICLE_ICON_DEFS,
    VEHICLE_FILL,
    VEHICLE_STROKE,
} from '../lib/vehicleIcons';

// The map is a single persistent instance now: its mode (dashboard vs trip),
// trip payload, and selection all come from the map store rather than props,
// so navigation never tears the map down.
const mapStore = useMapStore();
const scanPathsProgress = computed(() => mapStore.scanPathsProgress ?? { active: false, done: 0, total: 0 });
const scanPathsProgressPercent = computed(() => {
    const { done, total } = scanPathsProgress.value;
    if (!total) {
        return 0;
    }
    return Math.min(100, Math.round((done / total) * 100));
});
const { show: showToast } = useToast();

const mapContainer = ref(null);
let map = null;
let overlay = null;
let kitMarker = null;
let kitMarkerVehicleType = null;
// Bird's Eye admin mode (DEV-2116): one marker per driver, keyed by kit_id.
// Empty in driver mode. Bird's Eye has no single "your" dot — driverPositions
// drives these instead, so the single-kit kitMarker above stays null.
const adminMode = mapStore.adminMode;
let driverMarkers = {};
// Invisible Google Maps polylines laid over the live trail purely to host
// repeating direction arrows (deck.gl draws the visible line). One per trail
// segment; rebuilt when the trail grows, cleared when leaving Live mode or
// zooming below TRAIL_ARROWS_MIN_ZOOM. trailArrowsShown gates the per-idle
// visibility sync so a pan at high zoom doesn't needlessly rebuild them.
let kitTrailArrows = [];
let trailArrowsShown = false;
// DEV-2185: tracks whether the shared PATH_LAYERS_MIN_ZOOM floor is currently
// satisfied, so onBoundsChanged only repaints when eligibility flips (mirrors
// trailArrowsShown/rangeMarkersShown).
let pathLayersShown = false;
let hitMarkers = [];
// DEV-1965: a single transient logo marker dropped when a Live Hits Pending
// Repossession row is tapped. Lives outside the range/trip marker sets and is
// removed when the hit modal closes.
let focusHitMarker = null;
let rangeHitMarkers = [];
let rangeClusterer = null;
// DEV-2052: Staging Live markers cluster separately from Direct Live so the two
// hit types stay visually distinct and their counts don't merge into one cluster.
let rangeStagingClusterer = null;
let rangeMarkersShown = false;
let tripEndpointMarkers = [];
let noScanMarkers = [];
// F8: signature of the last no-scan marker render (eligibility + the no-scan
// zone set's ids/centroids). Lets updateNoScanMarkers skip the teardown/rebuild
// when nothing relevant changed, while a real zones edit still forces a rebuild.
let lastNoScanKey = null;
let boundsTimeout = null;
// F4: the snapped viewport (+zoom) of the last successful heatmap fetch. The
// idle path skips refetching when the view hasn't moved past the grid; reset on
// a layer toggle so a newly-active layer always fetches. Mirrors the server's
// 0.02° bbox snap (F1) so client and cache agree on what "unchanged" means.
let lastFetchedView = null;
let idleListener = null;
let mapClickListener = null;
let dragStartListener = null;
let zoomListener = null;
let deckClickHandled = false;
// Set on Live-mode entry when no position is known yet: the first kit-position
// poll arrives async, and we want that one update to zoom in (not passive
// follows).
let pendingRecenterZoom = false;

const STRIPE_PATTERN_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8"><path d="M-2,2 l4,-4 M0,8 l8,-8 M6,10 l4,-4" stroke="#4b5563" stroke-width="2"/></svg>`;
const stripePatternImage = new Image();
stripePatternImage.src = 'data:image/svg+xml,' + encodeURIComponent(STRIPE_PATTERN_SVG);
const STRIPE_PATTERN_MAPPING = {
    stripes: { x: 0, y: 0, width: 8, height: 8, mask: false },
};

// Grid the viewport snaps to before comparing idle-to-idle (F4); matches the
// server-side 0.02° bbox snap (F1).
const VIEW_SNAP_STEP = 0.02;
// Below this zoom the "DO NOT SCAN" pills overlap and clutter the metro view, so we hide them.
const NO_SCAN_LABEL_MIN_ZOOM = 11;
// DEV-2016: main-map range hit markers only appear once zoomed in (like the No Scan
// Zone labels), and cluster while there. DEV-2314: lowered to 11, matching the zone-label floor.
const RANGE_HIT_MARKERS_MIN_ZOOM = 11;
// DEV-2185: shared zoom floor for the long-window layers whose fetches carry no
// server-side row cap — scan paths, order density, and direct hit density.
// Below this zoom the FETCH is skipped (not just the render), since an
// unbounded or too-wide viewport at 15/30/60 days is what crashes the browser.
// DEV-2314: lowered to 11 so the path layers load one zoom level further out.
const PATH_LAYERS_MIN_ZOOM = 11;
// DEV-2061: live-trail direction arrows are fine detail — below this zoom they
// clutter (and opposite-direction passes on one road can overlap), so they only
// appear once zoomed in enough to read a single street. Tunable.
const TRAIL_ARROWS_MIN_ZOOM = 14;
// Street-level zoom the map opens at (and recenters to) in Live mode so the
// driver sees their immediate surroundings tight around the car. Tunable.
const LIVE_ZOOM = 16;
// DEV-2199: teammate overlay line color — cyan, distinct from own-line red
// [255,102,102], aging slate-black [30,41,55], Bird's-Eye crimson [220,20,60],
// and every user-configurable Zone::PRESET_COLORS boundary color.
const TEAMMATE_PATH_COLOR = [8, 145, 178, 210];

const loading = ref(false);
const isTripMode = computed(() => mapStore.viewMode === 'trip');

// The default desaturated "monochrome" roadmap styling. Applied at map creation
// and re-applied by the color toggle (DEV-2060) — color-on is the empty array
// (standard full-color Google styling). Hoisted to a single source so the
// constructor and the colorMapEnabled watcher can't drift.
const MONOCHROME_STYLES = [
    { elementType: 'geometry', stylers: [{ saturation: -100 }] },
    { elementType: 'labels.icon', stylers: [{ saturation: -100 }, { lightness: 50 }] },
    { elementType: 'labels.text.fill', stylers: [{ color: '#9e9e9e' }] },
    { elementType: 'labels.text.stroke', stylers: [{ color: '#ffffff' }] },
    { featureType: 'poi', elementType: 'all', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', elementType: 'all', stylers: [{ visibility: 'off' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ saturation: -100 }, { lightness: 30 }] },
    { featureType: 'road.highway', elementType: 'geometry', stylers: [{ lightness: 20 }] },
    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#c9c9c9' }] },
    { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#9e9e9e' }] },
    { featureType: 'landscape', elementType: 'geometry', stylers: [{ color: '#f5f5f5' }] },
];

async function initMap() {
    const apiKey = window.__CLARITY_SCOUT_CONFIG__?.googleMapsApiKey;
    if (!apiKey) {
        console.error('Google Maps API key not found');
        return;
    }

    const loader = new Loader({
        apiKey,
        version: '3.64',
    });

    const { Map } = await loader.importLibrary('maps');

    const center = { lat: 39.8282, lng: -98.5796 };

    map = new Map(mapContainer.value, {
        center,
        zoom: 7,
        minZoom: 4,
        disableDefaultUI: true,
        zoomControl: true,
        gestureHandling: 'greedy',
        styles: mapStore.colorMapEnabled ? [] : MONOCHROME_STYLES,
    });

    overlay = new DeckOverlay({
        layers: [],
        getTooltip: (info) => {
            // Escape every data-/org-derived value interpolated into the tooltip
            // html below: deck.gl injects these strings as raw HTML, so an
            // unescaped value (e.g. an admin-entered zone name/description) is a
            // stored-XSS vector that could exfiltrate the driver's scanner token.
            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            // Debug-only: per-hour scan-path segments carry a top-level time-range
            // label (no geohash properties), mirroring the Trips hover.
            if (info?.layer?.id === 'scan-paths' && info.object?.label) {
                return {
                    html: `<div>${esc(info.object.label)}</div>`,
                    style: {
                        background: 'rgba(0,0,0,0.85)',
                        color: '#fff',
                        padding: '4px 10px',
                        borderRadius: '6px',
                        fontSize: '13px',
                    },
                };
            }
            // Order cells (DEV-2031): show the exact combined address count for the
            // hovered geohash. Placed before the generic label branch so it wins.
            if (info?.layer?.id === 'heatmap-orders' && info.object?.properties) {
                const count = info.object.properties.count ?? 0;
                const geohash = info.object.properties.geohash;
                return {
                    html: `<div>${count} address${count === 1 ? '' : 'es'}${geohash ? `<div style="opacity:0.7;font-size:11px;font-family:monospace">${esc(geohash)}</div>` : ''}</div>`,
                    style: {
                        background: 'rgba(0,0,0,0.85)',
                        color: '#fff',
                        padding: '4px 10px',
                        borderRadius: '6px',
                        fontSize: '13px',
                    },
                };
            }
            // Direct Hit Density circles carry count/label on the object itself (no
            // GeoJSON properties), so handle them before the properties guard below.
            if (info?.layer?.id === 'direct-hotspot-circles' && info.object) {
                const count = info.object.count ?? 0;
                return {
                    html: `<div>${count} hit${count === 1 ? '' : 's'}</div>`,
                    style: {
                        background: 'rgba(0,0,0,0.85)',
                        color: '#fff',
                        padding: '4px 10px',
                        borderRadius: '6px',
                        fontSize: '13px',
                    },
                };
            }
            if (!info?.object?.properties) return null;
            const properties = info.object.properties;
            if (properties.label) {
                return {
                    html: `<div>${esc(properties.label)} (${esc(properties.count)})</div>`,
                    style: {
                        background: 'rgba(0,0,0,0.85)',
                        color: '#fff',
                        padding: '4px 10px',
                        borderRadius: '6px',
                        fontSize: '13px',
                    },
                };
            }
            const layerId = info.layer?.id;
            if ((layerId === 'zones-assigned' || layerId === 'zones-no-scan') && properties.is_assigned) {
                const name = properties.name ?? '';
                const description = properties.description ?? '';
                const isNoScan = !!properties.is_no_scan_zone;
                return {
                    html: `
                        <div style="font-weight:600;font-size:13px;">${esc(name)}</div>
                        ${description ? `<div style="font-size:12px;opacity:0.85;margin-top:2px;">${esc(description)}</div>` : ''}
                        ${isNoScan ? `<div style="color:#fca5a5;font-size:11px;font-weight:600;margin-top:4px;">⚠ Do not scan in this zone</div>` : ''}
                    `,
                    style: {
                        background: 'rgba(0,0,0,0.85)',
                        color: '#fff',
                        padding: '6px 10px',
                        borderRadius: '6px',
                        maxWidth: '240px',
                    },
                };
            }
            return null;
        },
    });
    overlay.setMap(map);

    // The map always boots in dashboard mode (first paint is the dashboard);
    // a later viewMode change flips it to trip mode via the watcher below.
    idleListener = map.addListener('idle', onBoundsChanged);

    // A user-initiated drag means they've taken control of the viewport —
    // stop auto-following the kit dot until they tap My Location again.
    // panTo()/setCenter() do NOT fire dragstart, so programmatic recenters
    // (follow, zone focus) won't self-disable.
    dragStartListener = map.addListener('dragstart', () => {
        mapStore.setFollowKit(false);
        // Like the driver recenter button, a manual pan stops following (admin).
        if (adminMode) mapStore.clearFollowedDriver();
    });

    loading.value = true;
    // The dashboard overlay owns range-based scan-path fetching; here we only
    // need kit position + zones to seed the initial view. In Bird's Eye there's no
    // single kit — the admin poll seeds all drivers — so seed driver positions
    // instead of the single-kit position (DEV-2116).
    await Promise.all([
        adminMode ? mapStore.fetchDriverPositions() : mapStore.fetchKitPosition(),
        mapStore.fetchZones(),
    ]);
    if (adminMode) updateDriverMarkers();
    loading.value = false;

    // Live mode opens tight on the car (LIVE_ZOOM); Historical stays at the
    // wider metro view. liveMode is the boot default, so the liveMode watcher
    // never fires at load — the initial zoom has to be set here.
    const bootZoom = mapStore.liveMode ? LIVE_ZOOM : 12;
    if (adminMode) {
        // Bird's Eye: frame all drivers (there's no single car to zoom to); falls
        // back to the wide US view the map constructed with when there are none.
        fitToDriverPositions();
    } else if (mapStore.kitPosition?.lat) {
        map.setCenter({ lat: mapStore.kitPosition.lat, lng: mapStore.kitPosition.lng });
        map.setZoom(bootZoom);
        updateKitMarker();
    } else if (mapStore.scanPaths.length > 0) {
        const firstPath = mapStore.scanPaths[0];
        if (firstPath?.path?.length > 0) {
            map.setCenter({ lat: firstPath.path[0][1], lng: firstPath.path[0][0] });
            map.setZoom(bootZoom);
        }
    } else if (mapStore.liveMode) {
        // No position yet — let the first live fix that lands zoom in once (the
        // kitPosition watcher consumes this), then leave the zoom to the driver.
        pendingRecenterZoom = true;
    }

    // If we mounted while already in trip mode (deep-link cold load to a
    // day/trip), render it now that the map exists.
    if (isTripMode.value) {
        enterTripModeView();
    }

    updateOverlayLayers();

    // Honor a zone-focus request that arrived before the map finished loading
    // (e.g. tapping a "View zone" toast link from another route, which routes
    // here and mounts the map fresh). The watcher above can't catch this since
    // the request was set while `map` was still null.
    if (!isTripMode.value && mapStore.zoneFocusRequest) {
        focusZoneFeature(mapStore.zoneFocusRequest.zoneId);
        mapStore.clearZoneFocus();
    }
}

function getAllPaths() {
    if (mapStore.tripPaths?.length > 0) return mapStore.tripPaths;
    if (mapStore.tripPath?.length > 0) return [{ path: mapStore.tripPath, color: [255, 102, 102] }];
    return [];
}

function initTripMode() {
    if (!map) return;

    const paths = getAllPaths();
    if (paths.length === 0) return;

    // Fit map to all trip path bounds
    const bounds = new google.maps.LatLngBounds();
    for (const tripObj of paths) {
        for (const coord of tripObj.path) {
            bounds.extend({ lat: coord[1], lng: coord[0] });
        }
    }

    // Also include hit locations in bounds
    if (mapStore.tripHits?.length) {
        for (const hit of mapStore.tripHits) {
            if (hit.lat && hit.lng) {
                bounds.extend({ lat: hit.lat, lng: hit.lng });
            }
        }
    }

    map.fitBounds(bounds, { padding: 40 });

    // Add hit markers using Google Maps markers for better visibility
    addHitMarkers();

    // Add start/end markers for trip paths
    addTripEndpointMarkers();
}

// Called once when the map enters trip mode (vs initTripMode, which re-runs on
// every trip-data change). Owns the trip-only "click empty space to deselect"
// listener so it isn't attached repeatedly. The `idle` heatmap listener is
// already wired in initMap and stays for both modes.
function enterTripModeView() {
    initTripMode();
    if (!mapClickListener && map) {
        mapClickListener = map.addListener('click', () => {
            if (deckClickHandled) return;
            mapStore.setSelectedTripIndex(null);
        });
    }
}

// Tear down trip-only artifacts when returning to the dashboard.
function clearTripArtifacts() {
    if (mapClickListener) {
        google.maps.event.removeListener(mapClickListener);
        mapClickListener = null;
    }
    hitMarkers.forEach(m => m.setMap(null));
    hitMarkers = [];
    tripEndpointMarkers.forEach(m => m.setMap(null));
    tripEndpointMarkers = [];
}

function addHitMarkers() {
    hitMarkers.forEach(m => m.setMap(null));
    hitMarkers = [];

    if (!mapStore.tripHits?.length || !map) return;

    for (const hit of mapStore.tripHits) {
        if (!hit.lat || !hit.lng) continue;

        const marker = new google.maps.Marker({
            map,
            position: { lat: hit.lat, lng: hit.lng },
            icon: logoMarkerIcon(),
            title: hit.plate || '',
            zIndex: 998,
        });
        marker.addListener('click', () => mapStore.setSelectedHit(hit));
        hitMarkers.push(marker);
    }
}

function logoMarkerIcon() {
    return {
        url: '/icons/icon-192.png',
        scaledSize: new google.maps.Size(32, 32),
        anchor: new google.maps.Point(16, 16),
    };
}

// DEV-2016: individual hit logo markers on the MAIN map (mapStore.rangeHits) — the
// selected date range in Historical, today's hits in Live so the driver sees their
// own hits on the route. Outside trip mode (which uses addHitMarkers), only once
// zoomed in past the threshold, and clustered while there. A cluster tap opens the
// sheet on the whole group (cycle); a lone marker tap opens the single hit.
function rangeHitMarkersEligible() {
    return !!(
        map
        && !isTripMode.value
        && mapStore.rangeHits?.length
        && (map.getZoom() ?? 0) >= RANGE_HIT_MARKERS_MIN_ZOOM
    );
}

function rangeHitsByTime(hits) {
    return [...hits].sort(
        (a, b) => new Date(a.time ?? a.hit_time ?? 0) - new Date(b.time ?? b.hit_time ?? 0)
    );
}

function clearRangeHitMarkers() {
    rangeClusterer?.clearMarkers();
    rangeStagingClusterer?.clearMarkers();
    rangeHitMarkers.forEach(m => m.setMap(null));
    rangeHitMarkers = [];
    rangeMarkersShown = false;
}

// Full rebuild — call on data/mode change. Clears then re-adds; the zoom + mode gate
// decides whether anything renders.
function addRangeHitMarkers() {
    clearRangeHitMarkers();

    if (!rangeHitMarkersEligible()) return;

    // DEV-2052: Direct Live ('L', or any non-staging/legacy payload) and Staging
    // Live ('S') markers cluster in separate sets so a mixed area shows two
    // distinct counts rather than one merged bubble. Both share the logo icon.
    const directMarkers = [];
    const stagingMarkers = [];

    for (const hit of mapStore.rangeHits) {
        if (!hit.lat || !hit.lng) continue;

        const marker = new google.maps.Marker({
            position: { lat: hit.lat, lng: hit.lng },
            icon: logoMarkerIcon(),
            title: hit.plate || '',
            zIndex: 997,
        });
        marker.__hit = hit;
        marker.addListener('click', () => {
            scoutTrack(SCOUT_EVENT.HIT_FOCUS, SCOUT_PAGE.MAP, { action: 'select', source: 'map' });
            mapStore.setSelectedHit(hit);
        });
        rangeHitMarkers.push(marker);
        (hit.hit_type === 'S' ? stagingMarkers : directMarkers).push(marker);
    }

    if (!rangeClusterer) {
        rangeClusterer = new MarkerClusterer({ map, onClusterClick: openRangeHitCluster });
    }
    if (!rangeStagingClusterer) {
        rangeStagingClusterer = new MarkerClusterer({ map, onClusterClick: openRangeHitCluster });
    }
    rangeClusterer.addMarkers(directMarkers);
    rangeStagingClusterer.addMarkers(stagingMarkers);
    rangeMarkersShown = true;
}

// Open the sheet on the cluster's hits (oldest→newest) instead of the default
// zoom-to-bounds, so the driver can cycle through them. Shared by both the
// Direct and Staging range clusterers (DEV-2052).
function openRangeHitCluster(_event, cluster) {
    const hits = rangeHitsByTime((cluster.markers ?? []).map(m => m.__hit).filter(Boolean));
    if (hits.length) {
        mapStore.setSelectedHitCluster(hits);
    }
}

// Zoom path (idle listener): toggle the visibility floor without rebuilding when
// already shown — MarkerClusterer re-clusters on zoom by itself.
function syncRangeHitVisibility() {
    const eligible = rangeHitMarkersEligible();
    if (eligible && !rangeMarkersShown) {
        addRangeHitMarkers();
    } else if (!eligible && rangeMarkersShown) {
        clearRangeHitMarkers();
    }
}

function rgbToHex(color) {
    return '#' + color.map(c => c.toString(16).padStart(2, '0')).join('');
}

function createPillIcon(label, bgColor, opacity = 1) {
    const width = label.length * 7 + 16;
    const height = 18;
    const radius = height / 2;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">
        <rect x="0" y="0" width="${width}" height="${height}" rx="${radius}" ry="${radius}" fill="${bgColor}" fill-opacity="${opacity}" />
        <text x="${width / 2}" y="13" text-anchor="middle" fill="white" fill-opacity="${opacity}" font-family="system-ui, -apple-system, sans-serif" font-size="9" font-weight="700" letter-spacing="0.5">${label}</text>
    </svg>`;
    return {
        url: 'data:image/svg+xml,' + encodeURIComponent(svg),
        scaledSize: new google.maps.Size(width, height),
        anchor: new google.maps.Point(width / 2, height / 2),
    };
}

function createVehicleIcon(vehicleType) {
    const def = VEHICLE_ICON_DEFS[vehicleType];
    if (!def) return null;

    return {
        path: def.path,
        fillColor: VEHICLE_FILL,
        fillOpacity: 1,
        strokeColor: VEHICLE_STROKE,
        strokeWeight: 1.5,
        scale: def.scale,
        anchor: new google.maps.Point(def.anchor[0], def.anchor[1]),
    };
}

function defaultKitIcon() {
    return {
        path: google.maps.SymbolPath.CIRCLE,
        // scale 11 ≈ 22px diameter — closer in size to the 32px vehicle icons
        // so a fleet with mixed icon-set/not-set kits looks consistent.
        scale: 11,
        fillColor: '#3B82F6',
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2,
    };
}

function addTripEndpointMarkers() {
    tripEndpointMarkers.forEach(m => m.setMap(null));
    tripEndpointMarkers = [];

    if (!map) return;

    const paths = getAllPaths();
    if (paths.length === 0) return;

    for (const tripObj of paths) {
        if (tripObj.path.length < 2) continue;

        const startCoord = tripObj.path[0];
        const endCoord = tripObj.path[tripObj.path.length - 1];
        const activeIdx = mapStore.activeTripIndex;
        const isActive = activeIdx === null || tripObj.tripIndex === activeIdx;

        // When >2 trips, only show pills for the actively hovered/selected trip
        if (paths.length > 2 && (activeIdx === null || tripObj.tripIndex !== activeIdx)) continue;

        const opacity = isActive ? 1 : 0.3;
        const tripNumber = String(tripObj.tripIndex + 1);

        const startMarker = new google.maps.Marker({
            map,
            position: { lat: startCoord[1], lng: startCoord[0] },
            icon: createPillIcon('START', '#16A34A', opacity),
            title: `Trip ${tripNumber} Start`,
            zIndex: 1000,
        });

        const endMarker = new google.maps.Marker({
            map,
            position: { lat: endCoord[1], lng: endCoord[0] },
            icon: createPillIcon('END', '#1F2937', opacity),
            title: `Trip ${tripNumber} End`,
            zIndex: 1000,
        });

        tripEndpointMarkers.push(startMarker, endMarker);
    }
}

function updateKitMarker() {
    const pos = mapStore.kitPosition;
    if (!pos?.lat || !map) return;

    const vehicleType = pos.vehicle_type ?? null;
    const icon = vehicleType
        ? (createVehicleIcon(vehicleType) ?? defaultKitIcon())
        : defaultKitIcon();

    if (kitMarker) {
        kitMarker.setPosition({ lat: pos.lat, lng: pos.lng });
        // Re-apply the icon when the vehicle type changes; setIcon is cheap
        // on Symbol-style icons.
        if (kitMarkerVehicleType !== vehicleType) {
            kitMarker.setIcon(icon);
            kitMarkerVehicleType = vehicleType;
        }
    } else {
        kitMarker = new google.maps.Marker({
            map,
            position: { lat: pos.lat, lng: pos.lng },
            icon,
            zIndex: 999,
        });
        kitMarkerVehicleType = vehicleType;
    }
}

// Bird's Eye (DEV-2116): if the kit has an assigned vehicle icon, show it;
// otherwise a plain red dot. Uniform across drivers (no per-driver tint) — the
// distinguishing color lives on the trail-on-hover, not the marker.
const DRIVER_RED_DOT = {
    path: 0, // google.maps.SymbolPath.CIRCLE
    scale: 8,
    fillColor: '#DC143C',
    fillOpacity: 1,
    strokeColor: '#ffffff',
    strokeWeight: 2,
};

// DEV-2253: a scan-derived dot is the driver's LAST SCAN position (up to ~15
// min stale, frozen while they drive without scanning), not a live GPS fix —
// dim it so the fleet board reads honestly, and say so in the tooltip.
const SCAN_DERIVED_OPACITY = 0.45;

function isScanDerived(pos) {
    return pos?.position_source === 'scan_path';
}

function driverIcon(vehicleType, scanDerived = false) {
    const icon = (vehicleType ? createVehicleIcon(vehicleType) : null) ?? DRIVER_RED_DOT;
    return scanDerived
        ? { ...icon, fillOpacity: SCAN_DERIVED_OPACITY, strokeOpacity: SCAN_DERIVED_OPACITY }
        : icon;
}

function driverTitle(pos) {
    const name = pos.driver_name || pos.name || '';
    return isScanDerived(pos) ? `${name} — last scan position (no live GPS)`.trim() : name;
}

// Reconcile one Google marker per driverPositions entry, keyed by kit_id; hover
// over a marker highlights that driver's trail (red). Adds new, moves existing,
// removes stale.
function updateDriverMarkers() {
    if (!map) return;
    // Driver dots persist across all date ranges (DEV-2310).
    const positions = mapStore.driverPositions ?? [];
    const seen = new Set();

    for (const pos of positions) {
        if (pos?.lat == null || pos?.lng == null) continue;
        const kitId = pos.kit_id;
        seen.add(kitId);
        const icon = driverIcon(pos.vehicle_type ?? null, isScanDerived(pos));
        const title = driverTitle(pos);
        const existing = driverMarkers[kitId];
        if (existing) {
            existing.setPosition({ lat: pos.lat, lng: pos.lng });
            existing.setIcon(icon);
            // Was never refreshed after creation — required now the title carries
            // provenance, or a kit that flips gps ⇄ scan_path keeps a stale tooltip.
            existing.setTitle(title);
        } else {
            const marker = new google.maps.Marker({
                map,
                position: { lat: pos.lat, lng: pos.lng },
                icon,
                title,
                zIndex: 999,
            });
            marker.addListener('mouseover', () => mapStore.setHoveredDriver(kitId));
            marker.addListener('mouseout', () => mapStore.setHoveredDriver(null));
            // Click to follow — like the recenter button (re)centers + tracks them.
            marker.addListener('click', () => followDriver(kitId));
            driverMarkers[kitId] = marker;
        }
    }

    // Drop drivers no longer present (deselected / went stale).
    for (const kitId of Object.keys(driverMarkers)) {
        if (!seen.has(Number(kitId)) && !seen.has(kitId)) {
            driverMarkers[kitId].setMap(null);
            delete driverMarkers[kitId];
        }
    }
}

// Bird's Eye: frame the viewport to the current driver dots. No-op (keeps the
// wide default) when none are loaded yet.
function fitToDriverPositions() {
    if (!map || typeof google === 'undefined') return;
    const positions = (mapStore.driverPositions ?? []).filter(p => p?.lat != null && p?.lng != null);
    if (positions.length === 0) return;
    const bounds = new google.maps.LatLngBounds();
    for (const p of positions) bounds.extend({ lat: p.lat, lng: p.lng });
    map.fitBounds(bounds, 64);
    // Don't punch in too far when drivers happen to be clustered.
    google.maps.event.addListenerOnce(map, 'idle', () => {
        if ((map.getZoom() ?? 0) > 13) map.setZoom(13);
    });
}

// Vertical pixel offset for the driver name plates: hug the marker when zoomed
// out (−15) and sit a little higher when zoomed in (−26), so it never reads as
// floating far above a small dot.
function nameplateOffsetY() {
    const z = (map && map.getZoom) ? (map.getZoom() ?? 12) : 12;
    if (z >= 15) return -26;
    if (z <= 10) return -15;
    return Math.round(-15 + (z - 10) * (-11 / 5));
}

// Keep the camera on the followed driver. zoomIn (on follow-start) punches in to
// LIVE_ZOOM once (mirrors the driver recenter button); subsequent live updates
// pan only, so the admin can still zoom out while following.
function panToFollowed(zoomIn = false) {
    if (!map) return;
    const id = mapStore.followedDriverKitId;
    if (id == null) return;
    const pos = (mapStore.driverPositions ?? []).find((p) => p.kit_id === id && p.lat != null && p.lng != null);
    if (!pos) return;
    map.panTo({ lat: pos.lat, lng: pos.lng });
    if (zoomIn && (map.getZoom() ?? 0) < LIVE_ZOOM) map.setZoom(LIVE_ZOOM);
}

// Follow a driver — works exactly like the driver app's recenter button:
// (re)center + zoom in + start following + a toast. A manual drag stops it
// (the dragstart listener clears the followed driver in admin mode).
function followDriver(kitId) {
    mapStore.setFollowedDriver(kitId);
    panToFollowed(true);
    const pos = (mapStore.driverPositions ?? []).find((p) => p.kit_id === kitId);
    const name = pos ? (pos.driver_name || pos.name || `Kit ${kitId}`) : 'driver';
    showToast(`Following ${name}`, { type: 'info', duration: 2000 });
}

// Direction arrows ride the live trail itself, not the vehicle: an invisible
// google.maps.Polyline per trail segment whose repeating arrow `icons` Google
// orients along each leg and leaves fixed in place, so the path shows which way
// the drive went and the arrows stay put as the kit moves on. Ride the trail
// across all date ranges (DEV-2310); the deck.gl PathLayer paints the red line underneath.
function updateTrailArrows() {
    removeTrailArrows();

    if (!map || typeof google === 'undefined') return;
    if (!mapStore.trailArrowsEnabled) return;   // DEV-2061 user toggle (default off)
    if (isTripMode.value) return;
    // Fine detail — only once zoomed in enough to read a street (also keeps
    // overlapping opposite-direction passes from clashing at the metro view).
    if ((map.getZoom() ?? 0) < TRAIL_ARROWS_MIN_ZOOM) return;

    const arrow = {
        icon: {
            path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
            // Medium-gray body (white washed out, black too heavy) with the
            // Scout primary-red outline so it stays on-brand and legible.
            scale: 3.4,
            fillColor: '#6B7280',
            fillOpacity: 1,
            strokeColor: '#FF6666',
            strokeWeight: 2,
        },
        // Spaced along the line; orientation follows each segment automatically.
        repeat: '90px',
    };

    for (const segment of mapStore.liveTrail ?? []) {
        const pts = (segment.path ?? []).filter(p => Array.isArray(p) && p.length >= 2);
        if (pts.length < 2) continue;

        kitTrailArrows.push(new google.maps.Polyline({
            map,
            path: pts.map(([lng, lat]) => ({ lat, lng })),
            strokeOpacity: 0,   // deck.gl draws the visible trail; this hosts only the arrows
            icons: [{ ...arrow, offset: '0' }],
            zIndex: 998,
        }));
    }

    trailArrowsShown = kitTrailArrows.length > 0;
}

function removeTrailArrows() {
    kitTrailArrows.forEach(p => p.setMap(null));
    kitTrailArrows = [];
    trailArrowsShown = false;
}

// Reveal/hide the trail arrows as the zoom crosses TRAIL_ARROWS_MIN_ZOOM
// (mirrors syncRangeHitVisibility). Cheap on a pan that doesn't change
// eligibility — only rebuilds/clears when crossing the boundary.
function syncTrailArrowVisibility() {
    const eligible = !!map && mapStore.trailArrowsEnabled && !isTripMode.value
        && (map.getZoom() ?? 0) >= TRAIL_ARROWS_MIN_ZOOM;
    if (eligible && !trailArrowsShown) {
        updateTrailArrows();
    } else if (!eligible && trailArrowsShown) {
        removeTrailArrows();
    }
}

// DEV-2185: shared eligibility for the scan-path, order-density, and direct
// hit-density layers — mirrors rangeHitMarkersEligible(). Consulted both to
// gate their fetch (below) and their render (updateOverlayLayers).
function pathLayersEligible() {
    return !!map && (map.getZoom() ?? 0) >= PATH_LAYERS_MIN_ZOOM;
}

// Zoom path (idle listener): repaint when eligibility crosses the floor even if
// the underlying data didn't change — mirrors syncRangeHitVisibility/
// syncTrailArrowVisibility.
function syncPathLayersVisibility() {
    const eligible = pathLayersEligible();
    if (eligible !== pathLayersShown) {
        pathLayersShown = eligible;
        updateOverlayLayers();
    }
}

function onBoundsChanged() {
    if (boundsTimeout) clearTimeout(boundsTimeout);
    boundsTimeout = setTimeout(() => {
        // DEV-2185: on the settled idle (getBounds() is valid now), publish the
        // viewport + eligibility so the imperative fetch callers (ScoutHome
        // range-select, admin driver-selector) can bbox-scope AND gate on a
        // settled, at-or-above-floor view — never firing a wide/unbounded fetch.
        mapStore.setMapBounds(getBoundsParams());
        mapStore.setPathLayersEligible(pathLayersEligible());
        // F4: only refetch heatmaps when the snapped viewport actually moved — a
        // sub-grid pan or a return to a prior view reuses the last result (and the
        // server cache). The cheap marker/visibility updates still run every idle.
        if (!sameView(snappedView(), lastFetchedView)) {
            refreshActiveHeatmaps();
            refreshScanPaths();
        }
        // Show/hide the DO NOT SCAN pills as the zoom crosses NO_SCAN_LABEL_MIN_ZOOM.
        updateNoScanMarkers();
        // Same idea for the range hit markers: reveal/hide as zoom crosses the threshold.
        syncRangeHitVisibility();
        // And the live-trail direction arrows (DEV-2061).
        syncTrailArrowVisibility();
        // And the scan-path/order/hit-density layers (DEV-2185).
        syncPathLayersVisibility();
    }, 300);
}

// The current viewport reduced to integer grid cells (+zoom) so idle-to-idle
// comparison ignores sub-grid jitter; null when the map isn't ready.
function snappedView() {
    const b = getBoundsParams();
    if (!b || !map) return null;
    const step = VIEW_SNAP_STEP;
    return {
        north: Math.ceil(b.north / step),
        south: Math.floor(b.south / step),
        east: Math.ceil(b.east / step),
        west: Math.floor(b.west / step),
        zoom: map.getZoom() ?? 0,
    };
}

function sameView(a, b) {
    return !!a && !!b
        && a.north === b.north && a.south === b.south
        && a.east === b.east && a.west === b.west
        && a.zoom === b.zoom;
}

function getBoundsParams() {
    if (!map) return null;
    const bounds = map.getBounds();
    if (!bounds) return null;

    const ne = bounds.getNorthEast();
    const sw = bounds.getSouthWest();
    return {
        north: ne.lat(),
        south: sw.lat(),
        east: ne.lng(),
        west: sw.lng(),
    };
}

async function refreshActiveHeatmaps() {
    if (!map || mapStore.activeHeatmaps.size === 0) return;
    // DEV-2185: below the shared zoom floor, skip the fetch entirely — these
    // layers carry no server-side row cap, so a too-wide viewport is the crash
    // trigger, not just a rendering nuisance. The render gate in
    // updateOverlayLayers hides any stale data left over from a higher zoom.
    if (!pathLayersEligible()) return;

    const boundsParams = getBoundsParams();
    if (!boundsParams) return;

    loading.value = true;

    const promises = [];
    // Direct Hit Density circles fetch on their own; the Direct + Staging order
    // layers union into one server call (DEV-2031).
    if (mapStore.activeHeatmaps.has('hits')) {
        promises.push(mapStore.fetchHitsHeatmap(boundsParams));
    }
    if (mapStore.activeHeatmaps.has('hotlist') || mapStore.activeHeatmaps.has('staging')) {
        promises.push(mapStore.fetchOrdersHeatmap(boundsParams));
    }
    // DEV-2199: teammate overlay snapshot — driver-only (never in Bird's Eye).
    if (!adminMode && mapStore.activeHeatmaps.has('teammates')) {
        promises.push(mapStore.fetchTeammatePaths(boundsParams));
    }

    await Promise.all(promises);
    loading.value = false;
    // Record the envelope we just fetched so the idle path can skip an unchanged view (F4).
    lastFetchedView = snappedView();
    updateOverlayLayers();
}

// DEV-2185: bbox-scoped scan-path refetch on pan/zoom, mirroring
// fetchOrdersHeatmap/fetchHitsHeatmap. Skips the FETCH (not just the render)
// below the zoom floor — scan-paths has no server-side row cap, unlike the
// row-capped /map/hits markers.
async function refreshScanPaths() {
    if (!map || isTripMode.value || mapStore.liveMode || !mapStore.currentRange) return;
    if (!pathLayersEligible()) return;

    const boundsParams = getBoundsParams();
    if (!boundsParams) return;

    // Soft-keep previous paths until the chunked fetch publishes this viewport.
    await mapStore.fetchScanPaths(mapStore.currentRange, boundsParams);
    // Record the fetched envelope so the idle guard (F4) can skip an unchanged
    // view — mirrors refreshActiveHeatmaps. Without this, lastFetchedView never
    // advances when no density layer is toggled on (refreshActiveHeatmaps
    // early-returns), so scan paths would re-fetch on every idle.
    lastFetchedView = snappedView();
    updateOverlayLayers();
}

// React to heatmap toggles in the store (overlays mutate the store; the map
// fetches data for newly-active layers and clears deactivated ones). Replaces
// the old component method that had to be invoked through a template ref.
async function syncHeatmaps(active, prev) {
    if (!map) return;
    // A layer toggle must fetch even when the viewport hasn't moved, so drop the
    // idle-guard memory and let the toggle path below stay authoritative (F4).
    lastFetchedView = null;
    const activeSet = new Set(active);
    const prevSet = new Set(prev ?? []);
    const boundsParams = getBoundsParams();
    const tasks = [];

    // Direct Hit Density circles — fetched/cleared on their own toggle. DEV-2185:
    // toggling on below the shared zoom floor is a no-op fetch — the render gate
    // hides it anyway, and it'll fetch once the user zooms/pans back in.
    if (prevSet.has('hits') && !activeSet.has('hits')) {
        mapStore.hitHeatmap = null;
    } else if (activeSet.has('hits') && !prevSet.has('hits') && boundsParams && pathLayersEligible()) {
        tasks.push(mapStore.fetchHitsHeatmap(boundsParams));
    }

    // Any change to the Direct/Staging order layers recomputes the union, or
    // clears it when neither is active (DEV-2031).
    const orderChanged = ['hotlist', 'staging'].some(t => activeSet.has(t) !== prevSet.has(t));
    if (orderChanged) {
        if ((activeSet.has('hotlist') || activeSet.has('staging')) && boundsParams && pathLayersEligible()) {
            tasks.push(mapStore.fetchOrdersHeatmap(boundsParams));
        } else {
            mapStore.ordersHeatmap = null;
        }
    }

    // DEV-2199: teammate overlay — driver-only, fetched/cleared on its own
    // toggle exactly like Direct Hit Density above.
    if (prevSet.has('teammates') && !activeSet.has('teammates')) {
        mapStore.teammatePaths = [];
    } else if (!adminMode && activeSet.has('teammates') && !prevSet.has('teammates') && boundsParams && pathLayersEligible()) {
        tasks.push(mapStore.fetchTeammatePaths(boundsParams));
    }

    if (tasks.length) {
        loading.value = true;
        await Promise.all(tasks);
        loading.value = false;
    }

    updateOverlayLayers();
}

function hexToRgb(hex, alpha = 255) {
    const safe = (hex || '').replace('#', '').padEnd(6, '0');
    return [
        parseInt(safe.slice(0, 2), 16) || 0,
        parseInt(safe.slice(2, 4), 16) || 0,
        parseInt(safe.slice(4, 6), 16) || 0,
        alpha,
    ];
}

function updateNoScanMarkers() {
    if (!map) return;

    // F8: above the zoom floor with unchanged zones, the pills already on the map
    // are correct — skip the teardown/rebuild that ran every idle. The key folds
    // in eligibility (zonesVisible, trip mode, zoom floor) and the no-scan set's
    // identity (ids + centroids), so a real zones change still rebuilds.
    const eligible = mapStore.zonesVisible && !isTripMode.value
        && (map.getZoom() ?? 0) >= NO_SCAN_LABEL_MIN_ZOOM;

    const assigned = mapStore.zones?.assigned ?? [];
    const noScan = eligible ? assigned.filter(f => f?.properties?.is_no_scan_zone) : [];
    const key = eligible
        ? JSON.stringify(noScan.map(f => [f.id, f.properties?.centroid]))
        : 'hidden';

    if (key === lastNoScanKey) return;
    lastNoScanKey = key;

    noScanMarkers.forEach(m => m.setMap(null));
    noScanMarkers = [];

    if (!eligible) return;

    for (const feature of noScan) {
        const centroid = feature.properties?.centroid;
        if (!Array.isArray(centroid) || centroid.length < 2) continue;

        const marker = new google.maps.Marker({
            map,
            position: { lat: centroid[1], lng: centroid[0] },
            icon: createPillIcon('DO NOT SCAN', '#DC2626', 1),
            title: `${feature.properties?.name ?? 'Zone'} — Do not scan`,
            zIndex: 1000,
            clickable: false,
        });
        noScanMarkers.push(marker);
    }
}

// --- Scan path / glyph helpers ---

// Scan path color. Today's drive (is_today flag, or a trip_date of today) is red
// — matching the live trail. Older trips are a neutral near-black that fades to
// transparent as the trip ages toward 60 days.
function scanPathColor(d) {
    const MAX_DAYS = 60;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const then = d.trip_date ? new Date(d.trip_date + 'T00:00:00').getTime() : NaN;

    if (d.is_today || (!Number.isNaN(then) && then >= today.getTime())) {
        return [255, 102, 102, 230];
    }

    const daysAgo = Number.isNaN(then) ? MAX_DAYS : (today.getTime() - then) / 86400000;
    const t = Math.max(0, Math.min(1, daysAgo / MAX_DAYS));
    return [30, 41, 55, Math.round(220 - t * 220)];
}

function cellBounds(ring) {
    let minLng = Infinity, minLat = Infinity, maxLng = -Infinity, maxLat = -Infinity;
    for (const [lng, lat] of ring) {
        if (lng < minLng) minLng = lng;
        if (lng > maxLng) maxLng = lng;
        if (lat < minLat) minLat = lat;
        if (lat > maxLat) maxLat = lat;
    }
    return { minLng, minLat, maxLng, maxLat };
}

// Center point of a polygon Feature's outer ring (cells are rectangles).
function cellCentroidFromFeature(feature) {
    const ring = feature.geometry?.coordinates?.[0];
    if (!ring?.length) return null;
    const b = cellBounds(ring);
    return [(b.minLng + b.maxLng) / 2, (b.minLat + b.maxLat) / 2];
}

// Direct Hit Density (DEV-2030) draws as a ~0.1mi circle centered on the hit
// cluster, sized in real meters so it scales with zoom. The per-cell fill color
// comes from the backend warning ramp (green = lone hit, red = dense cluster).
const DIRECT_HOTSPOT_RADIUS_METERS = 160;

function updateOverlayLayers() {
    if (!overlay) return;

    const layers = [];

    // Zone overlays — render beneath heatmaps so heatmaps stay visible on top.
    if (mapStore.zonesVisible && !isTripMode.value) {
        const assigned = mapStore.zones?.assigned ?? [];
        const isNoScan = (f) => !!f?.properties?.is_no_scan_zone;

        const assignedRegular = assigned.filter(f => !isNoScan(f));
        const noScanAssigned = assigned.filter(isNoScan);

        if (assignedRegular.length) {
            layers.push(new GeoJsonLayer({
                id: 'zones-assigned',
                data: { type: 'FeatureCollection', features: assignedRegular },
                stroked: true,
                filled: true,
                getFillColor: (f) => hexToRgb(f.properties?.color ?? '#2563eb', 32),
                getLineColor: (f) => hexToRgb(f.properties?.color ?? '#2563eb', 220),
                getLineWidth: 2,
                lineWidthUnits: 'pixels',
                pickable: true,
            }));
        }

        if (noScanAssigned.length) {
            layers.push(new GeoJsonLayer({
                id: 'zones-no-scan',
                data: { type: 'FeatureCollection', features: noScanAssigned },
                stroked: true,
                filled: true,
                getFillColor: [75, 85, 99, 60],
                getLineColor: [75, 85, 99, 220],
                getLineWidth: 2.5,
                lineWidthUnits: 'pixels',
                extensions: [new FillStyleExtension({ pattern: true })],
                fillPatternAtlas: stripePatternImage,
                fillPatternMapping: STRIPE_PATTERN_MAPPING,
                getFillPattern: () => 'stripes',
                getFillPatternScale: 1,
                pickable: true,
            }));
        }
    }

    // Direct Hit Density (DEV-2030, hits): aggregate cells render as warning-ramp
    // circles centered per cell. Kept OUT of the order union (DEV-2031 AC#5) so the
    // map isn't geohash-on-geohash.
    const directHotspotCircles = [];
    const hitsData = mapStore.hitHeatmap;

    if (mapStore.activeHeatmaps.has('hits') && hitsData?.features?.length && pathLayersEligible()) {
        for (const f of hitsData.features) {
            const position = cellCentroidFromFeature(f);
            if (!position) {
                continue;
            }
            directHotspotCircles.push({
                position,
                color: f.properties?.color ?? '#22C55E',
                count: f.properties?.count ?? 0,
                label: f.properties?.label ?? '',
            });
        }
    }

    // Direct + Staging Orders (DEV-2031): ONE unioned layer — a single outlined
    // geohash cell per location shaded by the combined count, never stacked. The
    // server already merged whichever order layers are active.
    // DEV-2600: paint-time band filter. The spread is a snapshot of membership;
    // the sibling watch on visibleOrderTiers is what repaints. An empty list
    // drops the layer so "all bands off" paints nothing.
    const ordersData = mapStore.ordersHeatmap;
    const visibleTiers = [...mapStore.visibleOrderTiers];
    const filteredOrders = ordersData?.features?.length
        ? { ...ordersData, features: ordersData.features.filter(f => visibleTiers.includes(f.properties?.tier)) }
        : null;

    if ((mapStore.activeHeatmaps.has('hotlist') || mapStore.activeHeatmaps.has('staging')) && filteredOrders?.features?.length && pathLayersEligible()) {
        // Filled cells so the combined count reads as a shade (DEV-2031 AC-3):
        // a translucent purple fill ramped by count, with a stronger outline of
        // the same tier color for cell definition over the basemap and zones.
        layers.push(new GeoJsonLayer({
            id: 'heatmap-orders',
            data: filteredOrders,
            stroked: true,
            filled: true,
            getFillColor: (f) => hexToRgb(f.properties?.color ?? '#4A148C', 150),
            getLineColor: (f) => hexToRgb(f.properties?.color ?? '#4A148C', 230),
            getLineWidth: 1.5,
            lineWidthUnits: 'pixels',
            // Pickable so a hover over a cell shows its combined address count and
            // wins the pick over the zone beneath it (zones render earlier/below).
            pickable: true,
        }));
    }

    // DEV-2199: teammate overlay — OTHER org drivers' driven lines (today + last
    // 7 days), a single flat cyan color, snapshot-fetched (never the live-position
    // poll). Driver-only (mapStore.teammatePaths stays empty in Bird's Eye) and
    // gated behind the shared DEV-2185 zoom floor like the other long-window layers.
    const teammateData = mapStore.teammatePaths;

    if (!adminMode && mapStore.activeHeatmaps.has('teammates') && teammateData?.length && pathLayersEligible()) {
        layers.push(new PathLayer({
            id: 'teammate-paths',
            data: teammateData,
            getPath: (d) => d.path,
            getColor: TEAMMATE_PATH_COLOR,
            getWidth: 4,
            widthMinPixels: 2,
            pickable: false,
        }));
    }

    if (directHotspotCircles.length) {
        layers.push(new ScatterplotLayer({
            id: 'direct-hotspot-circles',
            data: directHotspotCircles,
            getPosition: (d) => d.position,
            getFillColor: (d) => hexToRgb(d.color, 110),
            getRadius: DIRECT_HOTSPOT_RADIUS_METERS,
            radiusUnits: 'meters',
            stroked: true,
            getLineColor: [255, 255, 255, 230],
            lineWidthUnits: 'pixels',
            getLineWidth: 1.5,
            // Pickable so a hover shows the cluster's hit count over any zone beneath.
            pickable: true,
        }));
    }

    if (isTripMode.value) {
        const activeIdx = mapStore.activeTripIndex;
        let paths = getAllPaths();
        if (activeIdx !== null) {
            paths = [
                ...paths.filter(d => d.tripIndex !== activeIdx),
                ...paths.filter(d => d.tripIndex === activeIdx),
            ];
        }
        if (paths.length > 0) {
            layers.push(new PathLayer({
                id: 'trip-paths',
                data: paths,
                getPath: (d) => d.path,
                getColor: (d) => {
                    if (activeIdx === null) return d.color;
                    return d.tripIndex === activeIdx
                        ? [255, 102, 102]
                        : [200, 200, 200, 128];
                },
                getWidth: (d) => {
                    if (activeIdx === null) return 4;
                    return d.tripIndex === activeIdx ? 5 : 3;
                },
                widthMinPixels: 2,
                pickable: true,
                onHover: (info) => {
                    mapStore.setHoveredTripIndex(info.object?.tripIndex ?? null);
                },
                onClick: (info) => {
                    if (info.object) {
                        deckClickHandled = true;
                        const idx = info.object.tripIndex;
                        mapStore.setSelectedTripIndex(
                            mapStore.selectedTripIndex === idx ? null : idx);
                        setTimeout(() => { deckClickHandled = false; }, 50);
                    }
                },
                updateTriggers: {
                    getColor: [activeIdx],
                    getWidth: [activeIdx],
                },
            }));
        }
    } else if (!mapStore.liveMode) {
        // Scan path layer from store — historical mode only; Live shows the
        // real-time kit dot + live trail instead. DEV-2185: also gated on the
        // shared zoom floor — hidden below it regardless of what's cached.
        if (mapStore.scanPaths.length > 0 && pathLayersEligible()) {
            // Always color by the legend — today's drive red, older trips a
            // near-black that fades with age. Debug builds (SCANNER_DEBUG_MAP_ENABLED)
            // still attach a per-hour time-range label for the tooltip, but the
            // line color follows the legend (no multi-color overlay).
            const hasDebugPaths = mapStore.scanPaths.some((p) => p.label);
            layers.push(new PathLayer({
                id: 'scan-paths',
                data: mapStore.scanPaths,
                getPath: (d) => d.path,
                getColor: (d) => scanPathColor(d),
                getWidth: 4,
                widthMinPixels: 2,
                pickable: hasDebugPaths,
            }));
        }
    }

    // Live trail: the growing polyline of the current drive — fed by the
    // kit-position poll (current trip's last point), seeded from the saved trip.
    // Dashboard only; persists across date ranges (DEV-2310), layered above the
    // historical scan-paths; drawn last. The native kit dot paints above the overlay.
    // liveTrail is segments ({ path }); a continuous drive is a single segment,
    // so gate on a drawable segment (>=2 points), not the segment count.
    const drawableTrail = mapStore.liveTrail.filter(s => (s.path?.length ?? 0) >= 2);
    // The dot is the newest GPS point; the trail (decimated, separate poll) lags
    // a few seconds behind it, leaving a visible gap. Extend a COPY of the last
    // segment to the dot so the line reaches the car — unless that hop is a
    // power-off gap (> SEGMENT_BREAK_METERS), which must not draw a connector.
    if (!isTripMode.value && drawableTrail.length > 0) {
        const dot = mapStore.kitPosition;
        if (dot?.lat != null && dot?.lng != null) {
            const last = drawableTrail[drawableTrail.length - 1];
            const tail = last.path[last.path.length - 1];
            const dotPoint = [dot.lng, dot.lat];
            const gap = metersBetween(tail, dotPoint);
            if (gap > 1 && gap <= SEGMENT_BREAK_METERS) {
                drawableTrail[drawableTrail.length - 1] = { path: [...last.path, dotPoint] };
            }
        }
    }
    if (!isTripMode.value && drawableTrail.length > 0) {
        layers.push(new PathLayer({
            id: 'live-trail',
            data: drawableTrail,
            getPath: (d) => d.path,
            getColor: [255, 102, 102, 230],
            getWidth: 5,
            widthMinPixels: 3,
            capRounded: true,
            jointRounded: true,
            pickable: false,
        }));
    }

    // Bird's Eye (DEV-2116): all driver trails share one black PathLayer; the
    // hovered driver's trail turns red (hover via the marker or the line itself).
    // Replaces the single-kit live-trail above (empty in admin). Dashboard only;
    // persists across date ranges (DEV-2310).
    if (adminMode && !isTripMode.value) {
        const trailData = [];
        for (const [kitId, segs] of Object.entries(mapStore.driverTrailsByKit ?? {})) {
            for (const seg of segs ?? []) {
                if ((seg.path?.length ?? 0) >= 2) {
                    trailData.push({ path: seg.path, kitId: Number(kitId) });
                }
            }
        }
        if (trailData.length > 0) {
            const hovered = mapStore.hoveredDriverKitId;
            const followed = mapStore.followedDriverKitId;
            // Red when hovered OR followed (followed is sticky); else black.
            const isHot = (kitId) => kitId === hovered || kitId === followed;
            // While one trail is highlighted, dim the rest to 50%; with none
            // highlighted every trail returns to full-strength black.
            const hasHighlight = hovered != null || followed != null;
            const coldAlpha = hasHighlight ? 100 : 200;
            layers.push(new PathLayer({
                id: 'driver-trails',
                data: trailData,
                getPath: (d) => d.path,
                getColor: (d) => (isHot(d.kitId) ? [220, 20, 60, 240] : [30, 41, 55, coldAlpha]),
                getWidth: (d) => (isHot(d.kitId) ? 6 : 4),
                widthMinPixels: 3,
                capRounded: true,
                jointRounded: true,
                pickable: true,
                onHover: (info) => mapStore.setHoveredDriver(info.object ? info.object.kitId : null),
                onClick: (info) => { if (info.object) followDriver(info.object.kitId); },
                updateTriggers: { getColor: [hovered, followed], getWidth: [hovered, followed] },
            }));
        }

        // Name plates above each driver dot (toggle in the Drivers widget).
        const followed = mapStore.followedDriverKitId;
        const showPlates = mapStore.driverNameplatesEnabled;
        const plateData = (mapStore.driverPositions ?? []).filter(
            (p) => p.lat != null && p.lng != null && (showPlates || p.kit_id === followed),
        );
        if (plateData.length > 0) {
            layers.push(new TextLayer({
                id: 'driver-nameplates',
                data: plateData,
                getPosition: (d) => [d.lng, d.lat],
                getText: (d) => d.driver_name || d.name || `Kit ${d.kit_id}`,
                getSize: 13,
                getColor: [255, 255, 255],
                getPixelOffset: [0, nameplateOffsetY()],
                background: true,
                getBackgroundColor: (d) => (d.kit_id === followed ? [220, 20, 60, 235] : [30, 41, 55, 225]),
                backgroundPadding: [6, 3],
                fontWeight: 700,
                characterSet: 'auto',
                getTextAnchor: 'middle',
                getAlignmentBaseline: 'center',
                parameters: { depthTest: false },
                updateTriggers: { getBackgroundColor: [followed], getPixelOffset: [nameplateOffsetY()] },
            }));
        }
    }

    overlay.setProps({ layers });
    updateNoScanMarkers();
}

// My Location button. With a known position: pan back to the driver and resume
// following (zooming in if currently zoomed out). With no position yet there's
// nothing to recenter on — the dot comes from the kit-position poll — so the
// button is a no-op until the first fix lands.
function recenterOnKit() {
    const pos = mapStore.kitPosition;
    if (!map) return;
    if (pos?.lat == null || pos?.lng == null) return;
    map.panTo({ lat: pos.lat, lng: pos.lng });
    if ((map.getZoom() ?? 0) < LIVE_ZOOM) map.setZoom(LIVE_ZOOM);
    mapStore.setFollowKit(true);
    showToast('Recentering on your location', { type: 'info', duration: 2000 });
}

// DEV-1965: recenter on a tapped Live Hits Pending Repossession row and drop a
// one-off logo marker. Stops following the kit so the camera holds on the hit;
// the marker is cleared when the modal closes (see the selectedHit watcher).
function focusOnHit({ lat, lng }) {
    if (!map || typeof google === 'undefined') return;
    if (lat == null || lng == null) return;

    mapStore.setFollowKit(false);
    map.panTo({ lat, lng });
    if ((map.getZoom() ?? 0) < 15) map.setZoom(16);

    if (focusHitMarker) {
        focusHitMarker.setPosition({ lat, lng });
    } else {
        focusHitMarker = new google.maps.Marker({
            map,
            position: { lat, lng },
            icon: logoMarkerIcon(),
            zIndex: 999,
        });
    }
}

function clearFocusHitMarker() {
    if (focusHitMarker) {
        focusHitMarker.setMap(null);
        focusHitMarker = null;
    }
}

// Walk a GeoJSON geometry's coordinates into a LatLngBounds. Handles Polygon
// and MultiPolygon (the only zone geometry types). Returns null if empty.
function boundsFromGeometry(geometry) {
    if (!geometry?.coordinates) return null;
    const bounds = new google.maps.LatLngBounds();
    let extended = false;
    const walk = (node) => {
        if (typeof node?.[0] === 'number' && typeof node?.[1] === 'number') {
            bounds.extend({ lat: node[1], lng: node[0] });
            extended = true;
            return;
        }
        if (Array.isArray(node)) node.forEach(walk);
    };
    walk(geometry.coordinates);
    return extended ? bounds : null;
}

// Focus the map on a zone (from a "View zone" toast link). Fits the geometry
// bounds, falling back to the centroid. Focusing a zone means the driver is
// looking elsewhere, so follow mode turns off.
function focusZoneFeature(zoneId) {
    if (!map || typeof google === 'undefined') return;
    const feature = mapStore.findZoneFeature(zoneId);
    if (!feature) return;

    mapStore.setFollowKit(false);

    const bounds = boundsFromGeometry(feature.geometry);
    if (bounds) {
        map.fitBounds(bounds, { padding: 60 });
        return;
    }
    const centroid = feature.properties?.centroid;
    if (Array.isArray(centroid) && centroid.length >= 2) {
        map.panTo({ lat: centroid[1], lng: centroid[0] });
        map.setZoom(14);
    }
}

// Snapshot of the dashboard camera, captured right before entering trip mode
// so we can drop the driver back exactly where they were on return.
let savedDashboardView = null;

function restoreDashboardView() {
    if (!map) return;
    if (savedDashboardView) {
        map.setCenter(savedDashboardView.center);
        map.setZoom(savedDashboardView.zoom);
        savedDashboardView = null;
        return;
    }
    // No snapshot (e.g. deep-linked straight into a trip) — fall back to the
    // same seeding logic initMap uses (tight on the car in Live, wider otherwise).
    const fallbackZoom = mapStore.liveMode ? LIVE_ZOOM : 12;
    if (mapStore.kitPosition?.lat != null) {
        map.setCenter({ lat: mapStore.kitPosition.lat, lng: mapStore.kitPosition.lng });
        map.setZoom(fallbackZoom);
    } else if (mapStore.scanPaths.length > 0) {
        const firstPath = mapStore.scanPaths[0];
        if (firstPath?.path?.length > 0) {
            map.setCenter({ lat: firstPath.path[0][1], lng: firstPath.path[0][0] });
            map.setZoom(fallbackZoom);
        }
    }
}

// Live/Historical toggle drives the camera. Live snaps to the driver and zooms
// in (reusing the My Location / pendingRecenterZoom mechanism so the first GPS
// fix zooms in even if no position is known yet). DEV-2185: Historical no
// longer zooms OUT to fit the scan paths (fitToScanPaths) — that fought the
// PATH_LAYERS_MIN_ZOOM gate and assumed the whole range was already in memory,
// which conflicts with the bbox-scoped fetch; DateRangeControl's zoom-snap
// (pathZoomSnapRequest, below) brings the camera to the gate zoom instead.
watch(() => mapStore.liveMode, (on) => {
    if (!map || typeof google === 'undefined' || isTripMode.value) return;
    if (on) {
        mapStore.setFollowKit(true);
        pendingRecenterZoom = true;
        const pos = mapStore.kitPosition;
        if (pos?.lat != null && pos?.lng != null) {
            map.panTo({ lat: pos.lat, lng: pos.lng });
            if ((map.getZoom() ?? 0) < LIVE_ZOOM) map.setZoom(LIVE_ZOOM);
            pendingRecenterZoom = false;
        }
    }
    // Rebuild the deck overlay so the scan-paths layer (gated on !liveMode) is
    // added/dropped the moment the mode flips — otherwise leaving Live leaves no
    // historic lines until some other reactive change happens to repaint
    // (e.g. re-picking the default 'today' pill is a no-op and never redraws).
    updateOverlayLayers();
    // Repaint the direction arrows on the toggle (they persist across ranges now,
    // DEV-2310; updateTrailArrows self-guards on the trail existing + zoom).
    updateTrailArrows();
    addRangeHitMarkers();
});

// Drive the dashboard<->trip transition off store state so the single map
// instance reshapes itself instead of being remounted per view.
watch(() => mapStore.viewMode, (mode, prev) => {
    if (!map || typeof google === 'undefined') return;
    if (mode === 'trip') {
        if (prev !== 'trip') {
            savedDashboardView = { center: map.getCenter(), zoom: map.getZoom() };
        }
        enterTripModeView();
        updateOverlayLayers();
    } else if (prev === 'trip') {
        clearTripArtifacts();
        restoreDashboardView();
        updateKitMarker();
        updateOverlayLayers();
    }
    // Trip mode hides range markers; returning to the dashboard restores them.
    addRangeHitMarkers();
    // Drop the direction arrows in trip mode; restore them on the way back
    // (updateTrailArrows self-guards on isTripMode + zoom).
    updateTrailArrows();
});

// Watch for store changes that should trigger layer updates
watch(() => mapStore.scanPaths, () => {
    if (map && typeof google !== 'undefined') {
        updateOverlayLayers();
    }
}, { deep: true });

watch(() => mapStore.zones, () => {
    if (map && typeof google !== 'undefined') {
        updateOverlayLayers();
    }
}, { deep: true });

// A "View zone" toast link sets this request; consume it once the map is ready.
watch(() => mapStore.zoneFocusRequest, (req) => {
    if (!req || isTripMode.value) return;
    if (map && typeof google !== 'undefined') {
        focusZoneFeature(req.zoneId);
        mapStore.clearZoneFocus();
    }
});

// DEV-1965: recenter + drop the one-off marker when a Live Hits Pending
// Repossession row is tapped (works in Live and Historical alike).
watch(() => mapStore.hitFocusRequest, (req) => {
    if (!req || isTripMode.value) return;
    if (map && typeof google !== 'undefined') {
        focusOnHit(req);
        mapStore.clearHitFocus();
    }
});

// My Location button (now in the bottom bar) dispatches a recenter request the
// map consumes here, mirroring the hit/zone focus-request pattern.
watch(() => mapStore.kitRecenterRequest, (req) => {
    if (!req || isTripMode.value) return;
    recenterOnKit();
    mapStore.clearKitRecenter();
});

// DEV-2185: DateRangeControl bumps this when a 15/30/60 range is picked, so the
// scan-path layer (gated below PATH_LAYERS_MIN_ZOOM) is immediately visible
// instead of landing hidden.
//
// When we're BELOW the floor we snap the zoom in but deliberately do NOT flip
// the store's eligibility here: getBounds() lags setZoom(), so publishing
// eligible=true now would let the same-flush imperative fetch (ScoutHome range
// watch) fire with the stale, pre-snap WIDE bbox — the exact payload we're
// trying to prevent. Instead the resulting map idle → onBoundsChanged publishes
// the settled bounds + eligibility and fires the single scoped fetch
// (refreshScanPaths). When we're already at/above the floor the snap is a no-op
// and the view is settled, so we keep the store's bounds/eligibility current
// and the imperative caller fetches immediately.
watch(() => mapStore.pathZoomSnapRequest, (req) => {
    if (!req || isTripMode.value) return;
    if (map && (map.getZoom() ?? 0) < PATH_LAYERS_MIN_ZOOM) {
        map.setZoom(PATH_LAYERS_MIN_ZOOM);
    } else if (map) {
        mapStore.setMapBounds(getBoundsParams());
        mapStore.setPathLayersEligible(pathLayersEligible());
    }
    mapStore.clearPathZoomSnap();
});

// Auto-clear the transient hit marker once the hit modal closes.
watch(() => mapStore.selectedHit, (hit) => {
    if (!hit) clearFocusHitMarker();
});

watch(() => mapStore.zonesVisible, () => {
    if (map && typeof google !== 'undefined') {
        updateOverlayLayers();
    }
});

// Color basemap toggle (DEV-2060): re-style the roadmap in place via setOptions
// — [] is standard full-color Google styling, MONOCHROME_STYLES the default
// desaturated look. The deck.gl overlay sits on top, so no overlay rebuild is
// needed.
watch(() => mapStore.colorMapEnabled, (enabled) => {
    if (map && typeof google !== 'undefined') {
        map.setOptions({ styles: enabled ? [] : MONOCHROME_STYLES });
    }
});

// Direction-arrows toggle (DEV-2061): build/drop the arrow overlay when the user
// flips it. syncTrailArrowVisibility re-checks the trail + zoom, so toggling on
// while zoomed out (or with no trail yet) is a no-op until those conditions hold.
watch(() => mapStore.trailArrowsEnabled, () => {
    if (map && typeof google !== 'undefined') {
        syncTrailArrowVisibility();
    }
});

// A poll tick lands a new kit position AND a new trail batch together, firing
// both watchers below in the same flush. Coalesce their repaints into one
// updateOverlayLayers() per microtask so the full deck layer set rebuilds once
// per tick, not twice.
let overlayRepaintQueued = false;
function scheduleOverlayRepaint() {
    if (overlayRepaintQueued) return;
    overlayRepaintQueued = true;
    queueMicrotask(() => {
        overlayRepaintQueued = false;
        if (map && typeof google !== 'undefined') updateOverlayLayers();
    });
}

// Live trail grows (GPS append) / clears (leaving Live mode) — repaint the
// deck overlay. The trail ref is replaced on each append, so a shallow watch
// fires correctly without deep tracking.
watch(() => mapStore.liveTrail, () => {
    if (map && typeof google !== 'undefined') {
        scheduleOverlayRepaint();
        updateTrailArrows();
    }
});

watch(() => mapStore.kitPosition, () => {
    if (isTripMode.value || !map || typeof google === 'undefined') return;

    const pos = mapStore.kitPosition;
    // Live mode turned off (or position dropped) — remove the dot.
    if (!pos || pos.lat == null) {
        if (kitMarker) {
            kitMarker.setMap(null);
            kitMarker = null;
            kitMarkerVehicleType = null;
        }
        return;
    }

    updateKitMarker();
    // Repaint so the live trail's tail extends to the dot's new spot — the dot
    // poll can land a fresh position on ticks where the trail itself didn't grow.
    // Coalesced with the liveTrail watcher's repaint in the same flush.
    if (mapStore.liveMode) scheduleOverlayRepaint();
    // Keep the viewport glued to the driver while follow mode is on.
    if (mapStore.followKit && pos.lat != null && pos.lng != null) {
        map.panTo({ lat: pos.lat, lng: pos.lng });
        // A user-triggered locate (or the first live fix at boot) should zoom
        // in once; passive follow updates leave the zoom alone.
        if (pendingRecenterZoom) {
            if ((map.getZoom() ?? 0) < LIVE_ZOOM) map.setZoom(LIVE_ZOOM);
            pendingRecenterZoom = false;
        }
    }
}, { deep: true });

// Bird's Eye (DEV-2116): repaint driver markers + per-driver trails as the admin
// poll updates them. Admin mode only; inert in driver mode (collections stay empty).
watch(() => mapStore.driverPositions, () => {
    if (adminMode && map && typeof google !== 'undefined') {
        updateDriverMarkers();
        scheduleOverlayRepaint();
        panToFollowed(false); // keep tracking the followed driver as they move
    }
});

// driverTrailsByKit is a reactive object mutated in place per kit, so a shallow
// watch on it never fires; watch the store's cheap version counter instead, which
// the poll bumps whenever a trail actually changes (M7).
watch(() => mapStore.driverTrailsVersion, () => {
    if (adminMode && map && typeof google !== 'undefined') {
        scheduleOverlayRepaint();
    }
});

// Recolor the driver trails (black ↔ red) when the hovered driver changes.
watch(() => mapStore.hoveredDriverKitId, () => {
    if (adminMode && map && typeof google !== 'undefined') {
        scheduleOverlayRepaint();
    }
});

// Follow changed: repaint the sticky-red trail + name plate. The recenter/zoom
// itself happens in followDriver() (on click), mirroring the recenter button.
watch(() => mapStore.followedDriverKitId, () => {
    if (adminMode && map && typeof google !== 'undefined') {
        scheduleOverlayRepaint();
    }
});

// Name-plate toggle → repaint.
watch(() => mapStore.driverNameplatesEnabled, () => {
    if (adminMode && map && typeof google !== 'undefined') {
        scheduleOverlayRepaint();
    }
});

// Live/Historical toggle (admin): markers persist across ranges (DEV-2310);
// still repaint on toggle since the scan-path overlay and trail layering change.
watch(() => mapStore.liveMode, () => {
    if (adminMode && map && typeof google !== 'undefined') {
        updateDriverMarkers();
        scheduleOverlayRepaint();
    }
});

// Trip payload changes (from the store) — re-fit / repaint while in trip mode.
watch([() => mapStore.tripPath, () => mapStore.tripPaths], () => {
    if (isTripMode.value && map && typeof google !== 'undefined') {
        initTripMode();
        updateOverlayLayers();
    }
});

watch(() => mapStore.tripHits, () => {
    if (isTripMode.value && map && typeof google !== 'undefined') {
        addHitMarkers();
    }
});

// DEV-2016: main-map range hit markers repaint when the fetched list changes.
watch(() => mapStore.rangeHits, () => {
    if (map && typeof google !== 'undefined') {
        addRangeHitMarkers();
    }
});

watch(() => mapStore.activeTripIndex, () => {
    if (isTripMode.value && map && typeof google !== 'undefined') {
        updateOverlayLayers();
        addTripEndpointMarkers();
    }
});

// Heatmap layer toggles flow through the store now; fetch/clear + repaint here.
// The getter returns a fresh array each run, so the watch fires only when the
// reactive Set's membership changes, and hands us old/new for diffing.
watch(() => [...mapStore.activeHeatmaps], (active, prev) => {
    if (map && typeof google !== 'undefined') {
        syncHeatmaps(active, prev);
    }
});

// DEV-2600: Orders legend band toggles are a PAINT filter — repaint only.
// Never syncHeatmaps/fetchOrdersHeatmap: the data is already client-side and
// carries properties.tier. Spread so the getter tracks Set membership.
watch(() => [...mapStore.visibleOrderTiers], () => {
    if (map && typeof google !== 'undefined') {
        updateOverlayLayers();
    }
});

onMounted(() => {
    initMap();
});

onUnmounted(() => {
    if (boundsTimeout) clearTimeout(boundsTimeout);
    if (idleListener) {
        google.maps.event.removeListener(idleListener);
        idleListener = null;
    }
    if (mapClickListener) {
        google.maps.event.removeListener(mapClickListener);
        mapClickListener = null;
    }
    if (dragStartListener) {
        google.maps.event.removeListener(dragStartListener);
        dragStartListener = null;
    }
    if (zoomListener) {
        google.maps.event.removeListener(zoomListener);
        zoomListener = null;
    }
    if (kitMarker) {
        kitMarker.setMap(null);
        kitMarker = null;
        kitMarkerVehicleType = null;
    }
    // Bird's Eye driver markers (DEV-2116).
    for (const m of Object.values(driverMarkers)) m.setMap(null);
    driverMarkers = {};
    removeTrailArrows();
    hitMarkers.forEach(m => m.setMap(null));
    hitMarkers = [];
    clearRangeHitMarkers();
    rangeClusterer = null;
    rangeStagingClusterer = null;
    tripEndpointMarkers.forEach(m => m.setMap(null));
    tripEndpointMarkers = [];
    noScanMarkers.forEach(m => m.setMap(null));
    noScanMarkers = [];
    if (overlay) {
        overlay.finalize();
        overlay = null;
    }
    map = null;
});
</script>

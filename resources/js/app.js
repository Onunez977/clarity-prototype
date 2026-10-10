import axios from 'axios';
import annotationPlugin from 'chartjs-plugin-annotation';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import './otto/page-context.js';


window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Filament / Otto realtime (session-authed). Scout has its own Echo in
// clarity-scout/lib/echo.js (Sanctum + /api/broadcasting/auth) — leave that alone.
// Local: browser talks to lerd-soketi on localhost:6001 (ws). Do NOT nginx-proxy
// /app/ → Soketi here — Filament lives at /app and would break (unlike a dedicated
// wss host in prod). Overwatch's /app/ Soketi proxy shadows Filament /app HTTP.
const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
if (pusherKey) {
    window.Pusher = Pusher;

    if (import.meta.env.PROD) {
        window.Echo = new Echo({
            broadcaster: 'pusher',
            key: pusherKey,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
            wsHost: import.meta.env.VITE_PUSHER_HOST ?? 'wss.insightlpr.io',
            wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
            wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
            forceTLS: true,
            encrypted: true,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            authEndpoint: '/broadcasting/auth',
        });
    } else {
        // pusher-js 8.x shouldUseTLS() returns true whenever location.protocol
        // is https — it ignores forceTLS:false. Local Soketi on :6001 has no
        // TLS; localhost is a trustworthy origin so mixed ws:// is allowed.
        Pusher.Runtime.getProtocol = () => 'http:';

        window.Echo = new Echo({
            broadcaster: 'pusher',
            key: pusherKey,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
            wsHost: import.meta.env.VITE_PUSHER_HOST ?? 'localhost',
            wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
            wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
            forceTLS: false,
            encrypted: false,
            enabledTransports: ['ws'],
            disableStats: true,
            authEndpoint: '/broadcasting/auth',
        });
    }

    // Livewire copies Echo.socketId() into X-Socket-ID. When Soketi is down
    // that is JS undefined → the string "undefined", and Pusher PHP throws
    // on broadcast()->toOthers(). Only forward real Pusher socket IDs.
    const echoSocketId = window.Echo.socketId.bind(window.Echo);
    window.Echo.socketId = () => {
        const socketId = echoSocketId();
        return typeof socketId === 'string' && /^\d+\.\d+$/.test(socketId)
            ? socketId
            : undefined;
    };
}

window.filamentChartJsGlobalPlugins = [
    ...(window.filamentChartJsGlobalPlugins ?? []),
    annotationPlugin,
];

//import mapboxgl from 'mapbox-gl';
//import 'mapbox-gl/dist/mapbox-gl.css';
//mapboxgl.accessToken = import.meta.env.VITE_MAPBOX_TOKEN;

export function trackAnalytics(feature, page = null, params = {}){
    axios.post('/analytics/track', {
        'feature': feature,
        'page': page,
        'params': params,
    })
}
window.trackAnalytics = trackAnalytics;

// Auto-track clicks on elements with data-track attribute
document.addEventListener('click', function(event) {
    const el = event.target.closest('[data-track]');
    if (!el) return;

    const feature = el.getAttribute('data-track');
    const page = el.getAttribute('data-track-page') || null;
    if (feature) {
        trackAnalytics(feature, page, { action: 'click' });
    }
}, true);

window.userDti = function(){
    axios.post('/user/dti' , {
        "dti": Intl.DateTimeFormat().resolvedOptions()
    })
}
userDti()

export async function geocode(address){
    return await axios.post('/geocode',{
        'data':  address
    });
}
window.geocode = geocode;

export async function geocodeReverse(latitude, longitude, scan_id = null){
    return await axios.post('/geocode/reverse',{
        'data': latitude +','+longitude,
        'scan_id': scan_id,
    });
}
window.geocodeReverse = geocodeReverse;

export async function fetchLprVisitCount(lat, lng, startDate, endDate){
    return await axios.post('/lpr-visit-count', { lat, lng, start_date: startDate, end_date: endDate });
}
window.fetchLprVisitCount = fetchLprVisitCount;

const nearestAddressCache = new Map();
export async function fetchNearestGivenAddress(hotlistId, lat, lng) {
    const key = `${hotlistId}-${lat}-${lng}`;
    if (nearestAddressCache.has(key)) {
        return nearestAddressCache.get(key);
    }

    const response = await axios.post('/nearest-given-address', { hotlist_id: hotlistId, lat, lng });
    nearestAddressCache.set(key, response);

    return response;
}
window.fetchNearestGivenAddress = fetchNearestGivenAddress;

const vehicleCache = new Map();
export async function getScanVehicle(scan_id) {
    if (vehicleCache.has(scan_id)) {
        return vehicleCache.get(scan_id);
    }

    const response = await axios.post('/scan/vehicle', {
        'scan_id': scan_id,
    });

    vehicleCache.set(scan_id, response);

    return response;
}
window.getScanVehicle = getScanVehicle;

export function updateScanDisposition(scan_id, disposition, reason = 'other', rowKey = null){
    axios.post('/scan/disposition',{
        'scan_id': scan_id,
        'reason': reason,
        'disposition': disposition,
    }).then(response => {
        if (response.status === 200) {
            try {
                new FilamentNotification()
                    .title('Scan Disposition Updated')
                    .icon('heroicon-o-check')
                    .color('success')
                    .send()
            } catch (e) {
                //
            }

            if (rowKey) {
                document.getElementById(rowKey)?.parentElement.parentElement.parentElement.parentElement.remove();
            }
        }
    }).catch(error => {
        try {
            new FilamentNotification()
                .title('Scan Disposition Not Updated')
                .body('There was an error updating the scan disposition.')
                .icon('heroicon-o-exclamation-triangle')
                .color('warning')
                .send()
        } catch (e) {
            //
        }
    });
}
window.updateScanDisposition = updateScanDisposition;

const flexMatchCache = new Map();
export async function getFlexMatchHotlists(scan_id) {
    if (flexMatchCache.has(scan_id)) {
        return flexMatchCache.get(scan_id);
    }

    const response = await axios.post('/scan/flex-match-hotlists', {
        'scan_id': scan_id,
    });

    flexMatchCache.set(scan_id, response);

    return response;
}
window.getFlexMatchHotlists = getFlexMatchHotlists;

export function sendFlexMatchAlert(scan_id, hotlist_ids) {
    return axios.post('/scan/flex-match-alert', {
        'scan_id': scan_id,
        'hotlist_ids': hotlist_ids,
    }).then(response => {
        if (response.status === 200) {
            try {
                new FilamentNotification()
                    .title('ClearPlan Alert Sent')
                    .body(response.data?.message || 'Probable match hit(s) created and queued for ClearPlan.')
                    .icon('heroicon-o-check')
                    .color('success')
                    .send()
            } catch (e) {
                //
            }

            flexMatchCache.delete(scan_id);
        }

        return response;
    }).catch(error => {
        try {
            const message = error.response?.data?.message || 'There was an error sending the ClearPlan alert.';
            new FilamentNotification()
                .title('ClearPlan Alert Failed')
                .body(message)
                .icon('heroicon-o-exclamation-triangle')
                .color('warning')
                .send()
        } catch (e) {
            //
        }

        throw error;
    });
}
window.sendFlexMatchAlert = sendFlexMatchAlert;

export async function getKitUserPaths(kits, date_toggles, address = null){
    return await axios.post('/kit-user-path/paths',{
        'kits': kits,
        'date_toggles': date_toggles,
        'address': address,
    });
}
window.getKitUserPaths = getKitUserPaths;

export async function getKitUserGeojson(bounds, staging = false, tiers = []){
    return await axios.post('/kit-user-path/geojson', {
        ...bounds,
        staging,
        tiers,
    });
}
window.getKitUserGeojson = getKitUserGeojson;

export async function getKitUserHitDensity(bounds, tiers = []){
    return await axios.post('/kit-user-path/hit-density', {
        ...bounds,
        tiers,
    });
}
window.getKitUserHitDensity = getKitUserHitDensity;

export function filamentGoogleMapsStyles() {
    if (document.querySelector('html').classList.contains('dark')) {
        return [
            {
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#212121"
                    }
                ]
            },
            {
                "elementType": "labels.icon",
                "stylers": [
                    {
                        "visibility": "off"
                    }
                ]
            },
            {
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#757575"
                    }
                ]
            },
            {
                "elementType": "labels.text.stroke",
                "stylers": [
                    {
                        "color": "#212121"
                    }
                ]
            },
            // {
            //     "featureType": "administrative",
            //     "elementType": "geometry",
            //     "stylers": [
            //         {
            //             "color": "#757575"
            //         }
            //     ]
            // },
            {
                "featureType": "administrative.country",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#9e9e9e"
                    }
                ]
            },
            {
                "featureType": "administrative.land_parcel",
                "stylers": [
                    {
                        "visibility": "off"
                    }
                ]
            },
            {
                "featureType": "administrative.locality",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#bdbdbd"
                    }
                ]
            },
            {
                "featureType": "poi",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#757575"
                    }
                ]
            },
            {
                "featureType": "poi.park",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#181818"
                    }
                ]
            },
            {
                "featureType": "poi.park",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#616161"
                    }
                ]
            },
            {
                "featureType": "poi.park",
                "elementType": "labels.text.stroke",
                "stylers": [
                    {
                        "color": "#1b1b1b"
                    }
                ]
            },
            {
                "featureType": "road",
                "elementType": "geometry.fill",
                "stylers": [
                    {
                        "color": "#2c2c2c"
                    }
                ]
            },
            {
                "featureType": "road",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#8a8a8a"
                    }
                ]
            },
            {
                "featureType": "road.arterial",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#373737"
                    }
                ]
            },
            {
                "featureType": "road.highway",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#3c3c3c"
                    }
                ]
            },
            {
                "featureType": "road.highway.controlled_access",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#4e4e4e"
                    }
                ]
            },
            {
                "featureType": "road.local",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#616161"
                    }
                ]
            },
            {
                "featureType": "transit",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#757575"
                    }
                ]
            },
            {
                "featureType": "water",
                "elementType": "geometry",
                "stylers": [
                    {
                        "color": "#000000"
                    }
                ]
            },
            {
                "featureType": "water",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#3d3d3d"
                    }
                ]
            }
        ];
    } else {
        return [
            {
                "featureType": "administrative",
                "elementType": "labels.text.fill",
                "stylers": [
                    {
                        "color": "#444444"
                    }
                ]
            },
            {
                "featureType": "administrative.land_parcel",
                "elementType": "geometry",
                "stylers": [
                    {
                        "lightness": "84"
                    }
                ]
            },
            {
                "featureType": "landscape",
                "elementType": "all",
                "stylers": [
                    {
                        "color": "#f2f2f2"
                    }
                ]
            },
            {
                "featureType": "poi",
                "elementType": "all",
                "stylers": [
                    {
                        "visibility": "off"
                    }
                ]
            },
            {
                "featureType": "road",
                "elementType": "all",
                "stylers": [
                    {
                        "saturation": -100
                    },
                    {
                        "lightness": 45
                    }
                ]
            },
            {
                "featureType": "road.highway",
                "elementType": "all",
                "stylers": [
                    {
                        "visibility": "simplified"
                    }
                ]
            },
            {
                "featureType": "road.arterial",
                "elementType": "labels.icon",
                "stylers": [
                    {
                        "visibility": "off"
                    }
                ]
            },
            {
                "featureType": "transit",
                "elementType": "all",
                "stylers": [
                    {
                        "visibility": "off"
                    }
                ]
            },
            {
                "featureType": "water",
                "elementType": "all",
                "stylers": [
                    {
                        "color": "#676767"
                    },
                    {
                        "visibility": "on"
                    }
                ]
            }
        ];
    }
}
window.filamentGoogleMapsStyles = filamentGoogleMapsStyles;

import { PathLayer, GeoJsonLayer } from '@deck.gl/layers';
import { GoogleMapsOverlay as DeckOverlay } from '@deck.gl/google-maps';

window.filamentKitUserPath = function(mapEl)
{
    return {
        map: null,
        mapEl: null,
        overlay: null,
        centerAddress: null,
        layer: null,
        geohashLayer: null,
        stagingGeohashLayer: null,
        hitDensityLayer: null,
        loadingIndicator: null,
        initLoadingIndicator: null,
        geohashLoadingIndicator: null,
        stagingGeohashLoadingIndicator: null,
        hitDensityLoadingIndicator: null,
        geohashData: null,
        stagingGeohashData: null,
        hitDensityData: null,
        boundsChangeTimeout: null,

        callWire: function (thing) {},

        init: async function () {
            await this.asyncLoadMap();

            let env = this;

            this.$wire.on('reset-map', () => {
                this.clearGeojson();
                this.clearStagingGeojson();
                this.clearHitDensity();
            });

            this.$wire.on('load-geohash', (event) => {
                if (event.load) {
                    this.loadGeohash(event.tiers || []);
                } else {
                    this.clearGeojson();
                }
            });

            this.$wire.on('load-staging-geohash', (event) => {
                if (event.load) {
                    this.loadStagingGeohash(event.tiers || []);
                } else {
                    this.clearStagingGeojson();
                }
            });

            this.$wire.on('load-hit-density', (event) => {
                if (event.load) {
                    this.loadHitDensity(event.tiers || []);
                } else {
                    this.clearHitDensity();
                }
            });

            this.$wire.on('set-center', (event) => {
                this.initLoadingIndicator.classList.remove('!flex');

                if (event.lat && event.lng) {
                    this.centerAddress = [event.lat, event.lng];
                    this.center(event.lat, event.lng);
                } else {
                    this.centerAddress = null;
                }
            });

            this.$wire.on('load-data', (event) => {
                this.loadingIndicator.classList.remove('!hidden');
                this.initLoadingIndicator.classList.remove('!flex');

                window.getKitUserPaths(event.kits, event.date_toggles, event.address)
                    .then((res) => {
                        this.loadingIndicator.classList.add('!hidden');

                        if (res.status === 200) {
                            if (res.data.length === 0) {
                                env.sendNotification('There was no data found. Please adjust the filters and try again.', 'No Data Found', 'info');
                                env.layer = null;
                                env.updateOverlayLayers();
                            } else {
                                this.renderDataLayer(res.data, event.date_toggles);
                            }
                        } else {
                            env.sendNotification('Invalid data');
                        }
                    })
                    .catch((error) => {
                        console.log(error);

                        // Surface the server's 422 message ("Selection too large…") when present.
                        const message = error?.response?.data?.error
                            || 'There was a problem loading the route. Please adjust the filters and try again.';
                        env.sendNotification(message);
                        this.loadingIndicator.classList.add('!hidden');
                    });
            });
        },

        asyncLoadMap: async function () {
            if (! this.map) {
                this.mapEl = document.getElementById(mapEl) || mapEl || document.querySelector('div[x-ref="map"]');

                this.loadingIndicator = document.getElementById('map-loading-indicator');
                this.initLoadingIndicator = document.getElementById('init-loading-indicator');
                this.geohashLoadingIndicator = document.getElementById('geohash-loading-indicator');
                this.stagingGeohashLoadingIndicator = document.getElementById('staging-geohash-loading-indicator');
                this.hitDensityLoadingIndicator = document.getElementById('hit-density-loading-indicator');

                try {
                    this.map = new google.maps.Map(this.mapEl, {
                        center: {
                            lat: 39.8282,
                            lng: -98.5796,
                        },
                        clustering: false,
                        zoomControl: true,
                        controls: {
                            mapTypeControl: true,
                            scaleControl: false,
                            streetViewControl: false,
                            rotateControl: false,
                            fullscreenControl: true,
                            searchBoxControl: false,
                            zoomControl: true,
                        },
                        fit: true,
                        zoom: 7,
                        minZoom: 7,
                        styles: window.filamentGoogleMapsStyles(),
                    });

                    // Setup Overlay.
                    this.overlay = new DeckOverlay({
                        layers: [],
                        getTooltip: (object) => {
                            if (!object || !object.object) {
                                return null;
                            }

                            // Geohash layer tooltip
                            if (object.layer?.id === 'geohash-layer') {
                                const props = object.object.properties;
                                if (props?.label) {
                                    return {
                                        html: `<div>${props.label} (${props.count})</div>`,
                                        style: {
                                            background: 'rgba(0,0,0,0.85)',
                                            color: '#fff',
                                            padding: '4px 10px',
                                            borderRadius: '6px',
                                            fontSize: '13px',
                                            fontWeight: '500',
                                        }
                                    };
                                }
                                return null;
                            }

                            // Staging geohash layer tooltip
                            if (object.layer?.id === 'staging-geohash-layer') {
                                const props = object.object.properties;
                                if (props?.label) {
                                    return {
                                        html: `<div>Staging: ${props.label} (${props.count})</div>`,
                                        style: {
                                            background: 'rgba(0,0,0,0.85)',
                                            color: '#fff',
                                            padding: '4px 10px',
                                            borderRadius: '6px',
                                            fontSize: '13px',
                                            fontWeight: '500',
                                        }
                                    };
                                }
                                return null;
                            }

                            // Hit density layer tooltip
                            if (object.layer?.id === 'hit-density-layer') {
                                const props = object.object.properties;
                                if (props?.label) {
                                    return {
                                        html: `<div>${props.label} (${props.count})</div>`,
                                        style: {
                                            background: 'rgba(0,0,0,0.85)',
                                            color: '#fff',
                                            padding: '4px 10px',
                                            borderRadius: '6px',
                                            fontSize: '13px',
                                            fontWeight: '500',
                                        }
                                    };
                                }
                                return null;
                            }

                            // Path layer tooltip
                            return {
                                html: `<div class="text-2xl"><div>${object.object.name}</div></div>`,
                                style: {
                                    color: 'white',
                                    fontSize: '22px',
                                    'min-width': '200px',
                                    'max-width': '300px',
                                    'text-align': 'center',
                                }
                            };
                        },
                        pickingRadius: 10,
                        //drawPickingColors: true,
                        updateTriggers: {
                            // This tells deck.gl to recalculate radius when `currentYear` changes
                            path: 0
                        },
                        debug: false
                    });
                    this.overlay.setMap(this.map);

                    // All initialized.
                    this.$wire.dispatch('map-loaded');

                    this.loadingIndicator.classList.add('!hidden');
                    this.initLoadingIndicator.classList.add('!flex');
                } catch (error) {
                    let env = this;
                    setTimeout(function () {
                        env.asyncLoadMap();
                    }, 1000);
                }
            }
        },

        loadGeohash: function (tiers = []) {
            this.geohashLoadingIndicator.classList.remove('!hidden');
            this.geohashLoadingIndicator.classList.add('!flex');
            this.initLoadingIndicator.classList.remove('!flex');

            // Get the current map bounds
            const bounds = this.map.getBounds();
            const ne = bounds.getNorthEast();
            const sw = bounds.getSouthWest();

            // Make a POST request to the bounded geojson endpoint
            window.getKitUserGeojson({
                north: ne.lat(),
                east: ne.lng(),
                south: sw.lat(),
                west: sw.lng(),
            }, false, tiers)
                .then(response => {
                    if (response.status === 200) {
                        this.clearGeojson();

                        if (response.data.features && response.data.features.length > 0) {
                            this.geohashLayer = new GeoJsonLayer({
                                id: 'geohash-layer',
                                data: response.data,
                                getFillColor: (f) => {
                                    const hex = f.properties?.color ?? '#FFFFFF';
                                    const r = parseInt(hex.slice(1, 3), 16);
                                    const g = parseInt(hex.slice(3, 5), 16);
                                    const b = parseInt(hex.slice(5, 7), 16);
                                    return [r, g, b, 178]; // 178 ≈ 0.7 opacity
                                },
                                getLineColor: [255, 255, 255, 128],
                                getLineWidth: 0.5,
                                lineWidthUnits: 'pixels',
                                pickable: true,
                            });

                            this.updateOverlayLayers();

                            this.geohashData = response.data;
                        } else {
                            this.geohashData = null;
                            this.sendNotification('No heatmap data found for the current view. Try zooming out or adjusting filters.', 'No Heatmap Data', 'info');
                        }
                    } else {
                        this.geohashData = null;

                        this.sendNotification('Map Bounds too large, please zoom in and try again.');
                    }

                    this.geohashLoadingIndicator.classList.remove('!flex');
                    this.geohashLoadingIndicator.classList.add('!hidden');
                })
                .catch(error => {
                    console.error('Error loading geohash data:', error);
                    this.geohashLoadingIndicator.classList.remove('!flex');
                    this.geohashLoadingIndicator.classList.add('!hidden');

                    this.sendNotification('Error loading geohash data. The map bounds may be too large, please zoom in and try again');
                });
        },

        clearGeojson: function () {
            this.geohashLayer = null;
            this.updateOverlayLayers();
        },

        loadStagingGeohash: function (tiers = []) {
            this.stagingGeohashLoadingIndicator.classList.remove('!hidden');
            this.stagingGeohashLoadingIndicator.classList.add('!flex');
            this.initLoadingIndicator.classList.remove('!flex');

            const bounds = this.map.getBounds();
            const ne = bounds.getNorthEast();
            const sw = bounds.getSouthWest();

            window.getKitUserGeojson({
                north: ne.lat(),
                east: ne.lng(),
                south: sw.lat(),
                west: sw.lng(),
            }, true, tiers)
                .then(response => {
                    if (response.status === 200) {
                        this.clearStagingGeojson();

                        if (response.data.features && response.data.features.length > 0) {
                            this.stagingGeohashLayer = new GeoJsonLayer({
                                id: 'staging-geohash-layer',
                                data: response.data,
                                getFillColor: (f) => {
                                    const hex = f.properties?.color ?? '#FFFFFF';
                                    const r = parseInt(hex.slice(1, 3), 16);
                                    const g = parseInt(hex.slice(3, 5), 16);
                                    const b = parseInt(hex.slice(5, 7), 16);
                                    return [r, g, b, 178];
                                },
                                getLineColor: [255, 255, 255, 128],
                                getLineWidth: 0.5,
                                lineWidthUnits: 'pixels',
                                pickable: true,
                            });

                            this.updateOverlayLayers();

                            this.stagingGeohashData = response.data;
                        } else {
                            this.stagingGeohashData = null;
                            this.sendNotification('No staging heatmap data found for the current view. Try zooming out or adjusting filters.', 'No Staging Data', 'info');
                        }
                    } else {
                        this.stagingGeohashData = null;
                        this.sendNotification('Map Bounds too large, please zoom in and try again.');
                    }

                    this.stagingGeohashLoadingIndicator.classList.remove('!flex');
                    this.stagingGeohashLoadingIndicator.classList.add('!hidden');
                })
                .catch(error => {
                    console.error('Error loading staging geohash data:', error);
                    this.stagingGeohashLoadingIndicator.classList.remove('!flex');
                    this.stagingGeohashLoadingIndicator.classList.add('!hidden');

                    this.sendNotification('Error loading staging geohash data. The map bounds may be too large, please zoom in and try again');
                });
        },

        clearStagingGeojson: function () {
            this.stagingGeohashLayer = null;
            this.updateOverlayLayers();
        },

        loadHitDensity: function (tiers = []) {
            this.hitDensityLoadingIndicator.classList.remove('!hidden');
            this.hitDensityLoadingIndicator.classList.add('!flex');
            this.initLoadingIndicator.classList.remove('!flex');

            const bounds = this.map.getBounds();
            const ne = bounds.getNorthEast();
            const sw = bounds.getSouthWest();

            window.getKitUserHitDensity({
                north: ne.lat(),
                east: ne.lng(),
                south: sw.lat(),
                west: sw.lng(),
            }, tiers)
                .then(response => {
                    if (response.status === 200) {
                        this.clearHitDensity();

                        if (response.data.features && response.data.features.length > 0) {
                            this.hitDensityLayer = new GeoJsonLayer({
                                id: 'hit-density-layer',
                                data: response.data,
                                getFillColor: (f) => {
                                    const hex = f.properties?.color ?? '#FFFFFF';
                                    const r = parseInt(hex.slice(1, 3), 16);
                                    const g = parseInt(hex.slice(3, 5), 16);
                                    const b = parseInt(hex.slice(5, 7), 16);
                                    return [r, g, b, 178];
                                },
                                getLineColor: [255, 255, 255, 128],
                                getLineWidth: 0.5,
                                lineWidthUnits: 'pixels',
                                pickable: true,
                            });

                            this.updateOverlayLayers();

                            this.hitDensityData = response.data;
                        } else {
                            this.hitDensityData = null;
                            this.sendNotification('No hit density data found for the current view. Try zooming out or adjusting filters.', 'No Hit Density Data', 'info');
                        }
                    } else {
                        this.hitDensityData = null;
                        this.sendNotification('Map Bounds too large, please zoom in and try again.');
                    }

                    this.hitDensityLoadingIndicator.classList.remove('!flex');
                    this.hitDensityLoadingIndicator.classList.add('!hidden');
                })
                .catch(error => {
                    console.error('Error loading hit density data:', error);
                    this.hitDensityLoadingIndicator.classList.remove('!flex');
                    this.hitDensityLoadingIndicator.classList.add('!hidden');

                    this.sendNotification('Error loading hit density data. The map bounds may be too large, please zoom in and try again');
                });
        },

        clearHitDensity: function () {
            this.hitDensityLayer = null;
            this.updateOverlayLayers();
        },

        updateOverlayLayers: function () {
            const layers = [];
            if (this.hitDensityLayer) layers.push(this.hitDensityLayer);
            if (this.stagingGeohashLayer) layers.push(this.stagingGeohashLayer);
            if (this.geohashLayer) layers.push(this.geohashLayer);
            if (this.layer) layers.push(this.layer);
            this.overlay.setProps({ layers });
        },

        center: function(lat, lng, zoom = 7) {
            this.map.panTo({ lat: lat, lng: lng });
            this.map.setZoom(zoom);
        },

        renderDataLayer: function(data, layerId = '') {
            let env = this;

            setTimeout(function () {
                env.layer = new PathLayer({
                    id: 'path-layer-' + layerId,
                    data: data,
                    getPath: (d) => d.path,
                    getColor: (d) =>  d.color,
                    getWidth: (d) => 2,
                    widthScale: 10,
                    widthMinPixels: 2,
                    pickable: true,
                    positionFormat: `XY`,
                    drawPickingColors: true,
                });

                env.updateOverlayLayers();

                // Re-center Map if not centered.
                const first = data[0];
                if (env.centerAddress === null && first) {
                    if (first.path[0][1]) {
                        env.center(first.path[0][1] ?? 39.8282, first.path[0][0] ?? -98.5796);
                    } else {
                        // Error, reset
                        env.center(39.8282, -98.5796, 7);
                        env.sendNotification('Invalid data for centering the map');
                    }
                }
            }, 500)
        },

        sendNotification: function(message = '', title = 'There was an error', color = 'warning') {
            this.initLoadingIndicator.classList.remove('!flex');

            try {
                new FilamentNotification()
                    .title(title)
                    .body(message)
                    .icon('heroicon-o-exclamation-triangle')
                    .color(color)
                    .send()
            } catch (e) {
                //
            }
        },
    };
}

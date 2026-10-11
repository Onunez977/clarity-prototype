import { MarkerClusterer } from "@googlemaps/markerclusterer";

window.filamentMapsWidget = function(mapEl)
{
    return {
        map: null,
        bounds: null,
        mapEl: null,
        data: null,
        markers: [],
        layers: [],
        clusterer: null,
        center: null,
        centerCircle: null,
        locked: false,
        config: {
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
            gmaps: "",
            layers: [],
            zoom: 3.5,
        },

        callWire: function (thing) {},

        init: async function () {
            window.addEventListener(
                "filament-maps-widget/setMapCenter",
                (event) => {
                    this.recenter(event.detail);
                }
            );

            window.addEventListener(
                "filament-maps-widget/updateMapData",
                (event) => {
                    this.update(event.detail.data);

                    if (event.detail.center) {
                        this.addCenterCircle(event.detail.center);
                        this.recenter(event.detail);
                    } else if (this.centerCircle) {
                        this.centerCircle.setMap(null);
                    }
                }
            );

            window.addEventListener(
                "filament-maps-widget/loadMap",
                (event) => {
                    this.asyncLoadMap();
                }
            );
        },

        asyncLoadMap: async function () {
            if (! this.map) {
                this.mapEl = document.getElementById(mapEl) || mapEl || document.querySelector('div[x-ref="map"]');

                try {
                    this.map = await new google.maps.Map(this.mapEl, {
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
                        zoom: 3.5,
                        styles: window.filamentGoogleMapsStyles(),
                    });

                    this.center = this.config.center;

                    this.show(true);
                } catch (error) {
                    let env = this;
                    setTimeout(function () {
                        env.asyncLoadMap();
                    }, 1000);
                }
            }
        },

        show: function (force = false) {
            if (this.markers.length === 1) {
                this.map.setCenter(this.markers[0].getPosition());
                this.map.setZoom(12);
            } else if (this.markers.length > 0 && this.config.fit) {
                this.fitToBounds(force);
            } else {
                this.map.setCenter(this.config.center);
                this.map.setZoom(this.config.zoom);
            }

            setTimeout(() => {
                this.locked = false;
            }, 500);
        },
        update: async function (data) {
            if (! this.map) {
                await this.asyncLoadMap();
            }

            if (! this.locked) {
                this.locked = true;
                this.data = data;

                this.removeAndCreateMarkers();

                this.show();
            }
        },

        removeAndCreateMarkers: function () {
            if (this.config.clustering) {
                if (! this.clusterer) {
                    this.clusterer = new MarkerClusterer({
                        map: this.map,
                        markers: [],
                    });
                }
            }

            // Remove existing Markers.
            this.markers.forEach(function (marker) {
                marker.setMap(null);
                marker.setOpacity(0);
                marker.setZIndex(-999);
            })

            this.clusterer?.removeMarkers(this.markers);
            this.markers = [];

            // Create new Markers.
            this.markers = this.data.map((location) => {
                return this.createMarker(location);
            });

            this.clusterer?.addMarkers(this.markers);
        },
        createMarker: function (location) {
            const label = location.label;

            const marker = new google.maps.Marker({
                position: location.location,
                title: label,
                map: this.map,
            });

            let content = '<div class="map-info-window">';

            if (location.image) {
                content += '<img src="' + location.image + '" alt="' + label + '">';
            }

            content += '<p><strong>' + label + '</strong></p>';

            if (location.address) {
                content += '<p><strong>Address:</strong> ' + location.vehicle + '</p>';
            }

            if (location.vehicle) {
                content += '<p><strong>Vehicle:</strong> ' + location.vehicle + '</p>';
            }

            content += '</div>'

            marker.addListener("click", () => {
                if (marker.getMap()) {
                    const infoWindow = new google.maps.InfoWindow({
                        content: content,
                        disableAutoPan: true,
                    });

                    infoWindow.open({
                        anchor: marker,
                        map: this.map,
                    });
                } else {
                    marker.setMap(null);
                }
            });

            return marker;
        },

        recenter: async function (data) {
            if (! this.map) {
                await this.asyncLoadMap();
            }

            this.map.panTo({ lat: data.center.lat, lng: data.center.lng });
        },
        addCenterCircle: async function(centerPoint, radius = 161) {
            if (! this.map) {
                await this.asyncLoadMap();
            }

            if (this.centerCircle) {
                this.centerCircle.setMap(null);
            }

            this.centerCircle = new google.maps.Circle({
                strokeColor: "#FF6666",
                strokeOpacity: 0.2,
                strokeWeight: 1,
                fillColor: "#FF6666",
                fillOpacity: 0.2,
                map: this.map,
                center: new google.maps.LatLng(centerPoint),
                radius: radius,
                visible: true,
            });
        },
        fitToBounds: function (force = false) {
            if (this.markers.length > 0 && (this.config.fit || force)) {
                this.bounds = new google.maps.LatLngBounds();

                for (const marker of this.markers) {
                    this.bounds.extend(marker.getPosition());
                }

                this.map.fitBounds(this.bounds);
            }
        },
    };
}

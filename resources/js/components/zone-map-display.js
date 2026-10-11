function createPillIcon(label, bgColor) {
    const width = label.length * 7 + 16;
    const height = 18;
    const radius = height / 2;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">
        <rect x="0" y="0" width="${width}" height="${height}" rx="${radius}" ry="${radius}" fill="${bgColor}" />
        <text x="${width / 2}" y="13" text-anchor="middle" fill="white" font-family="system-ui, -apple-system, sans-serif" font-size="9" font-weight="700" letter-spacing="0.5">${label}</text>
    </svg>`;
    return {
        url: 'data:image/svg+xml,' + encodeURIComponent(svg),
        scaledSize: new google.maps.Size(width, height),
        anchor: new google.maps.Point(width / 2, height / 2),
    };
}

function featureToRings(feature) {
    const geom = feature?.geometry;
    if (!geom) return [];
    if (geom.type === 'Polygon') return geom.coordinates;
    if (geom.type === 'MultiPolygon') return geom.coordinates.flat();
    return [];
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function extendBoundsWithGeometry(bounds, geometry) {
    if (!geometry) return;
    const walk = (coords) => {
        if (typeof coords[0] === 'number') {
            bounds.extend({ lat: coords[1], lng: coords[0] });
        } else {
            for (const c of coords) walk(c);
        }
    };
    walk(geometry.coordinates);
}

window.filamentZoneMapDisplay = function (config) {
    return {
        initial: config.initial,
        map: null,
        noScanMarkers: [],
        stripedOverlay: null,
        tooltipEl: null,

        init: async function () {
            await this.asyncLoadMap();
        },

        asyncLoadMap: async function () {
            try {
                this.map = new google.maps.Map(this.$refs.map, {
                    center: { lat: 39.8282, lng: -98.5796 },
                    zoom: 4,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: true,
                    gestureHandling: 'cooperative',
                });

                this.renderFeatures();
            } catch (error) {
                const env = this;
                setTimeout(function () {
                    env.asyncLoadMap();
                }, 1000);
            }
        },

        renderFeatures() {
            const payload = this.initial ?? {};
            const allFeatures = payload.features?.features ?? [];
            const badges = payload.no_scan_badges ?? [];

            const regular = allFeatures.filter(f => !f.properties?.is_no_scan_zone);
            const noScan = allFeatures.filter(f => f.properties?.is_no_scan_zone);

            this.tooltipEl = this.createTooltip();

            const data = new google.maps.Data({ map: this.map });
            data.addGeoJson({ type: 'FeatureCollection', features: regular });
            data.setStyle((feature) => {
                const color = feature.getProperty('color') || '#2563eb';
                return {
                    strokeColor: color,
                    strokeWeight: 2,
                    fillColor: color,
                    fillOpacity: 0.2,
                    clickable: true,
                };
            });

            const showFromDataEvent = (event) => {
                const props = {
                    zone_name: event.feature.getProperty('zone_name'),
                    zone_description: event.feature.getProperty('zone_description'),
                    is_no_scan_zone: event.feature.getProperty('is_no_scan_zone'),
                };
                this.showTooltip(props, event.domEvent);
            };
            data.addListener('mouseover', showFromDataEvent);
            data.addListener('mousemove', (event) => this.moveTooltip(event.domEvent));
            data.addListener('mouseout', () => this.hideTooltip());

            if (noScan.length) {
                this.stripedOverlay = this.createStripedOverlay(noScan, badges);
                this.stripedOverlay.setMap(this.map);
            }

            const bounds = new google.maps.LatLngBounds();
            for (const f of allFeatures) extendBoundsWithGeometry(bounds, f.geometry);

            if (!bounds.isEmpty()) this.map.fitBounds(bounds, 32);
        },

        createTooltip() {
            const el = document.createElement('div');
            el.style.cssText = [
                'position:absolute',
                'pointer-events:none',
                'background:rgba(0,0,0,0.85)',
                'color:#fff',
                'padding:6px 10px',
                'border-radius:6px',
                'font-size:13px',
                'line-height:1.35',
                'max-width:260px',
                'z-index:1000',
                'display:none',
                'box-shadow:0 4px 12px rgba(0,0,0,0.25)',
                'font-family:system-ui,-apple-system,sans-serif',
            ].join(';');
            this.map.getDiv().appendChild(el);
            return el;
        },

        showTooltip(props, domEvent) {
            const name = props?.zone_name;
            if (!name) return;
            const description = props.zone_description;
            const isNoScan = !!props.is_no_scan_zone;
            this.tooltipEl.innerHTML = `
                <div style="font-weight:600;font-size:13px;">${escapeHtml(name)}</div>
                ${description ? `<div style="font-size:12px;opacity:0.85;margin-top:2px;">${escapeHtml(description)}</div>` : ''}
                ${isNoScan ? '<div style="color:#fca5a5;font-size:11px;font-weight:600;margin-top:4px;">⚠ Do not scan in this zone</div>' : ''}
            `;
            this.tooltipEl.style.display = 'block';
            this.moveTooltip(domEvent);
        },

        moveTooltip(domEvent) {
            if (!domEvent || !this.tooltipEl) return;
            const rect = this.map.getDiv().getBoundingClientRect();
            const x = domEvent.clientX - rect.left + 12;
            const y = domEvent.clientY - rect.top + 12;
            this.tooltipEl.style.left = x + 'px';
            this.tooltipEl.style.top = y + 'px';
        },

        hideTooltip() {
            if (this.tooltipEl) this.tooltipEl.style.display = 'none';
        },

        createStripedOverlay(features, badges) {
            const self = this;
            const SVG_NS = 'http://www.w3.org/2000/svg';
            const PATTERN_ID = 'zone-stripes-' + Math.random().toString(36).slice(2);

            class StripedZoneOverlay extends google.maps.OverlayView {
                constructor() {
                    super();
                    this.features = features;
                    this.badges = badges ?? [];
                    this.div = null;
                    this.svg = null;
                    this.pathsGroup = null;
                    this.pillsGroup = null;
                }

                onAdd() {
                    this.div = document.createElement('div');
                    this.div.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;pointer-events:none;';

                    this.svg = document.createElementNS(SVG_NS, 'svg');
                    this.svg.setAttribute('width', '1');
                    this.svg.setAttribute('height', '1');
                    this.svg.style.cssText = 'position:absolute;left:0;top:0;overflow:visible;pointer-events:none;';

                    const defs = document.createElementNS(SVG_NS, 'defs');
                    const pattern = document.createElementNS(SVG_NS, 'pattern');
                    pattern.setAttribute('id', PATTERN_ID);
                    pattern.setAttribute('patternUnits', 'userSpaceOnUse');
                    pattern.setAttribute('width', '8');
                    pattern.setAttribute('height', '8');
                    pattern.setAttribute('patternTransform', 'rotate(45)');

                    const bg = document.createElementNS(SVG_NS, 'rect');
                    bg.setAttribute('width', '8');
                    bg.setAttribute('height', '8');
                    bg.setAttribute('fill', 'rgba(75,85,99,0.22)');
                    pattern.appendChild(bg);

                    const stripe = document.createElementNS(SVG_NS, 'line');
                    stripe.setAttribute('x1', '0');
                    stripe.setAttribute('y1', '0');
                    stripe.setAttribute('x2', '0');
                    stripe.setAttribute('y2', '8');
                    stripe.setAttribute('stroke', '#4b5563');
                    stripe.setAttribute('stroke-width', '2');
                    pattern.appendChild(stripe);

                    defs.appendChild(pattern);
                    this.svg.appendChild(defs);

                    this.pathsGroup = document.createElementNS(SVG_NS, 'g');
                    this.svg.appendChild(this.pathsGroup);

                    this.pillsGroup = document.createElementNS(SVG_NS, 'g');
                    this.pillsGroup.style.pointerEvents = 'none';
                    this.svg.appendChild(this.pillsGroup);

                    this.div.appendChild(this.svg);
                    this.getPanes().overlayMouseTarget.appendChild(this.div);
                }

                draw() {
                    if (!this.pathsGroup) return;
                    const projection = this.getProjection();
                    if (!projection) return;

                    while (this.pathsGroup.firstChild) {
                        this.pathsGroup.removeChild(this.pathsGroup.firstChild);
                    }

                    for (const feature of this.features) {
                        const rings = featureToRings(feature);
                        if (!rings.length) continue;

                        const subpaths = rings.map(ring => {
                            const pts = ring.map(([lng, lat]) => {
                                const p = projection.fromLatLngToDivPixel(new google.maps.LatLng(lat, lng));
                                return `${p.x},${p.y}`;
                            });
                            return 'M' + pts.join(' L') + ' Z';
                        });

                        const path = document.createElementNS(SVG_NS, 'path');
                        path.setAttribute('d', subpaths.join(' '));
                        path.setAttribute('fill', `url(#${PATTERN_ID})`);
                        path.setAttribute('fill-rule', 'evenodd');
                        path.setAttribute('stroke', '#4b5563');
                        path.setAttribute('stroke-width', '2.5');
                        path.setAttribute('stroke-opacity', '0.9');
                        path.style.pointerEvents = 'auto';
                        path.style.cursor = 'pointer';

                        const props = feature.properties ?? {};
                        path.addEventListener('mouseenter', (ev) => self.showTooltip(props, ev));
                        path.addEventListener('mousemove', (ev) => self.moveTooltip(ev));
                        path.addEventListener('mouseleave', () => self.hideTooltip());

                        this.pathsGroup.appendChild(path);
                    }

                    while (this.pillsGroup.firstChild) {
                        this.pillsGroup.removeChild(this.pillsGroup.firstChild);
                    }

                    for (const badge of this.badges) {
                        const coords = badge.centroid;
                        if (!Array.isArray(coords) || coords.length < 2) continue;

                        const center = projection.fromLatLngToDivPixel(new google.maps.LatLng(coords[1], coords[0]));
                        const label = 'DO NOT SCAN';
                        const width = label.length * 7 + 16;
                        const height = 18;
                        const rx = height / 2;
                        const x = center.x - width / 2;
                        const y = center.y - height / 2;

                        const pill = document.createElementNS(SVG_NS, 'g');

                        const rect = document.createElementNS(SVG_NS, 'rect');
                        rect.setAttribute('x', String(x));
                        rect.setAttribute('y', String(y));
                        rect.setAttribute('width', String(width));
                        rect.setAttribute('height', String(height));
                        rect.setAttribute('rx', String(rx));
                        rect.setAttribute('ry', String(rx));
                        rect.setAttribute('fill', '#DC2626');
                        pill.appendChild(rect);

                        const text = document.createElementNS(SVG_NS, 'text');
                        text.setAttribute('x', String(center.x));
                        text.setAttribute('y', String(center.y + 3));
                        text.setAttribute('text-anchor', 'middle');
                        text.setAttribute('fill', '#ffffff');
                        text.setAttribute('font-family', 'system-ui, -apple-system, sans-serif');
                        text.setAttribute('font-size', '9');
                        text.setAttribute('font-weight', '700');
                        text.setAttribute('letter-spacing', '0.5');
                        text.textContent = label;
                        pill.appendChild(text);

                        this.pillsGroup.appendChild(pill);
                    }
                }

                onRemove() {
                    if (this.div?.parentNode) this.div.parentNode.removeChild(this.div);
                    this.div = null;
                    this.svg = null;
                    this.pathsGroup = null;
                    this.pillsGroup = null;
                }
            }
            return new StripedZoneOverlay();
        },
    };
};

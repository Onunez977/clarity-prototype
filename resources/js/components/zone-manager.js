window.filamentZoneManager = function (config) {
    return {
        zonesUrl: config.zonesUrl,
        zipSearchUrl: config.zipSearchUrl,
        noScanColor: config.noScanColor,

        map: null,
        drawingManager: null,
        loadingIndicator: null,
        tooltipEl: null,

        // Existing zones rendered as read-only overlays, keyed by zone id.
        // Each entry: { overlays: [google.maps.Polygon], color, isNoScan, name, description, listeners: [] }
        zoneOverlays: {},

        // The zone currently being edited. `null` until a zone is selected or
        // "New Zone" is clicked.
        editingId: null,
        editing: false,

        // Editable state owned by JS and synced back to $wire.data.definition.
        polygonOverlays: [],
        zipOverlays: {},
        zipCodes: [],
        zipQuery: '',
        zipError: '',
        polygonCount: 0,

        // The ZIP currently selected (editable) on the map, plus its path
        // listeners keyed by zip so they can be removed on deselect/detach.
        selectedZip: null,
        zipListeners: {},
        zipClickListeners: {},
        detachNotice: '',
        zipEditVertexCap: 2000,

        drawing: false,

        // True while beginEdit is hydrating polygons/zips from server state; we
        // don't want syncEditingState during hydration to mark the form dirty.
        hydrating: false,

        // Bumped every time the editing context resets (teardown). Async zip
        // hydration captures it and bails if it changes mid-flight, so a slow
        // fetch can't repaint editable overlays after a save/cancel cleared them.
        editToken: 0,

        init: async function () {
            this.loadingIndicator = document.getElementById('zone-map-loading-indicator');

            await this.asyncLoadMap();

            this.createTooltip();

            this.$wire.on('zone-selected', (event) => {
                this.beginEdit(event.zoneId, event.definition || {});
            });

            this.$wire.on('start-new-zone', (event) => {
                this.beginEdit(null, event.definition || { polygons: [], zip_codes: [] });
            });

            this.$wire.on('cancel-edit', () => {
                this.endEdit();
            });

            this.$wire.on('zone-saved', () => {
                this.endEdit();
                this.reloadZones();
            });

            this.$wire.on('zone-deleted', (event) => {
                this.removeZoneOverlay(event.zoneId);
                this.endEdit();
            });

            await this.reloadZones();

            // Deep-link load (Edit from the Zones list → ?zone=id): mount()
            // dispatches 'zone-selected', but that event races this component's
            // listener registration (Livewire mount events can fire before Alpine
            // init finishes), so the editor often never opened — leaving the zone
            // un-editable and the map fit to *all* zones instead of the selected
            // one. Pull the preselected zone here, after the overlays exist, so
            // Edit reliably opens it in edit mode and recenters on it.
            const preselectedId = this.$wire.get('selectedZoneId');
            if (preselectedId && this.editingId !== preselectedId) {
                const data = this.$wire.get('data') || {};
                this.beginEdit(preselectedId, data.definition || {});
            } else if (!preselectedId) {
                const data = this.$wire.get('data') || {};
                const def = data.definition || {};
                const hasZips = Array.isArray(def.zip_codes) && def.zip_codes.length > 0;
                const hasPolygons = Array.isArray(def.polygons) && def.polygons.length > 0;
                const hasName = typeof data.name === 'string' && data.name.trim() !== '';
                if ((hasZips || hasPolygons || hasName) && !this.editing) {
                    this.beginEdit(null, def);
                }
            }
        },

        asyncLoadMap: async function () {
            // Alpine's `x-ref` isn't processed on elements marked `x-ignore`
            // (which we need for the map container so Alpine doesn't fight
            // Google's DOM mutations). Look up the element manually instead.
            const mapEl = (this.$el && this.$el.querySelector('[x-ref="map"]'))
                || document.querySelector('[x-ref="map"]');

            if (!mapEl) {
                console.error('zone-manager: map container not found in DOM');
                return;
            }

            const start = Date.now();

            // Google Maps is loaded with `loading=async`, which means we must use
            // `google.maps.importLibrary(...)` to get the actual libraries; the
            // legacy `&libraries=drawing` URL param alone does NOT populate
            // `google.maps.drawing`. Wait for `importLibrary` to exist, then
            // explicitly import what we need.
            while (!(window.google && window.google.maps && typeof window.google.maps.importLibrary === 'function')) {
                if (Date.now() - start > 15000) {
                    console.error('zone-manager: Google Maps SDK never loaded');
                    return;
                }
                await new Promise(r => setTimeout(r, 200));
            }

            try {
                const [{ Map }, drawing] = await Promise.all([
                    google.maps.importLibrary('maps'),
                    google.maps.importLibrary('drawing'),
                ]);

                this.map = new Map(mapEl, {
                    center: { lat: 39.8282, lng: -98.5796 },
                    zoom: 4,
                    mapTypeControl: true,
                    streetViewControl: false,
                    fullscreenControl: true,
                });

                this.drawingManager = new drawing.DrawingManager({
                    drawingMode: null,
                    drawingControl: false,
                    polygonOptions: this.editablePolygonStyle('#2563eb'),
                });
                this.drawingManager.setMap(this.map);

                google.maps.event.addListener(this.drawingManager, 'polygoncomplete', (polygon) => {
                    this.drawingManager.setDrawingMode(null);
                    this.drawing = false;

                    // A polygon with fewer than 3 vertices isn't a real boundary;
                    // discard it instead of letting it linger as an editable overlay.
                    if (polygon.getPath().getLength() < 3) {
                        polygon.setMap(null);
                        return;
                    }

                    this.attachEditablePolygonHandlers(polygon);
                    this.polygonOverlays.push(polygon);
                    this.syncEditingState();
                });
            } catch (e) {
                console.error('zone-manager: failed to initialize map', e);
            }
        },

        reloadZones: async function () {
            if (this.loadingIndicator) this.loadingIndicator.classList.remove('!hidden');

            try {
                const response = await fetch(this.zonesUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error('Failed to load zones');

                const body = await response.json();
                this.renderAllZones(body.zones || []);
            } catch (e) {
                console.error('zone-manager: failed to load zones', e);
            } finally {
                if (this.loadingIndicator) this.loadingIndicator.classList.add('!hidden');
            }
        },

        renderAllZones: function (features) {
            // Clear existing overlays.
            Object.values(this.zoneOverlays).forEach(entry => {
                entry.overlays.forEach(o => {
                    entry.listeners.forEach(l => google.maps.event.removeListener(l));
                    o.setMap(null);
                });
            });
            this.zoneOverlays = {};

            const bounds = new google.maps.LatLngBounds();
            let any = false;

            features.forEach(feature => {
                if (!feature.geometry) return;

                const props = feature.properties || {};
                const id = props.id;
                const color = props.is_no_scan_zone ? this.noScanColor : (props.color || '#2563eb');
                const overlays = [];
                const listeners = [];

                this.geometryToRingSets(feature.geometry).forEach(rings => {
                    const paths = this.ringSetToPaths(rings, bounds);
                    any = any || paths.length > 0;

                    const polygon = new google.maps.Polygon({
                        ...this.readonlyZoneStyle(color),
                        paths,
                    });
                    polygon.setMap(this.map);

                    listeners.push(polygon.addListener('click', () => this.onZoneClick(id)));
                    listeners.push(polygon.addListener('mouseover', (e) => this.showTooltip(id, e)));
                    listeners.push(polygon.addListener('mousemove', (e) => this.moveTooltip(e)));
                    listeners.push(polygon.addListener('mouseout', () => this.hideTooltip()));
                    overlays.push(polygon);
                });

                this.zoneOverlays[id] = {
                    overlays,
                    color,
                    listeners,
                    name: props.name,
                    description: props.description,
                    isNoScan: !!props.is_no_scan_zone,
                };
            });

            if (any && !bounds.isEmpty() && this.editingId === null) {
                this.map.fitBounds(bounds, 64);
            }

            // Re-apply the editing state. The zone being edited is shown as an
            // editable polygon, so hide the read-only overlay we just (re)built
            // for it — otherwise it sits on top of the editable shape and
            // swallows the edge-drag clicks. This matters on a deep-link load
            // (Edit from the list), where renderAllZones can run *after*
            // beginEdit; endEdit/beginEdit restore it via restoreAllReadonlyOverlays.
            if (this.editingId !== null) {
                if (this.zoneOverlays[this.editingId]) {
                    this.zoneOverlays[this.editingId].overlays.forEach(o => o.setMap(null));
                }
                this.applyDimming();
            }
        },

        createTooltip: function () {
            if (this.tooltipEl) return;
            const el = document.createElement('div');
            el.style.cssText = [
                'position: absolute',
                'pointer-events: none',
                'z-index: 50',
                'background: rgba(0, 0, 0, 0.85)',
                'color: #fff',
                'padding: 6px 10px',
                'border-radius: 6px',
                'font-size: 12px',
                'line-height: 1.35',
                'max-width: 260px',
                'box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3)',
                'display: none',
            ].join(';');
            // Mount inside the same wrapper as the map so positioning is relative.
            const host = this.$el || document.body;
            host.style.position = host.style.position || 'relative';
            host.appendChild(el);
            this.tooltipEl = el;
        },

        showTooltip: function (zoneId, event) {
            // No tooltip while drawing or for the zone currently being edited.
            if (this.drawing) return;
            if (this.editingId === zoneId) return;

            const entry = this.zoneOverlays[zoneId];
            if (!entry || !this.tooltipEl) return;

            const noScanWarning = entry.isNoScan
                ? '<div style="color:#fca5a5;font-size:11px;font-weight:600;margin-top:4px;">⚠ Do not scan in this zone</div>'
                : '';
            const description = entry.description
                ? `<div style="font-size:11px;opacity:0.85;margin-top:2px;">${this.escapeHtml(entry.description)}</div>`
                : '';

            this.tooltipEl.innerHTML = `
                <div style="font-weight:600;font-size:13px;">${this.escapeHtml(entry.name || 'Zone')}</div>
                ${description}
                ${noScanWarning}
            `;
            this.tooltipEl.style.display = 'block';
            this.moveTooltip(event);
        },

        moveTooltip: function (event) {
            if (!this.tooltipEl || this.tooltipEl.style.display === 'none') return;
            const host = this.$el;
            if (!host) return;
            const rect = host.getBoundingClientRect();
            // event.domEvent is the underlying MouseEvent from Google Maps' poly events.
            const domEvent = event && event.domEvent ? event.domEvent : event;
            if (!domEvent || domEvent.clientX === undefined) return;
            const x = domEvent.clientX - rect.left + 12;
            const y = domEvent.clientY - rect.top + 12;
            this.tooltipEl.style.left = x + 'px';
            this.tooltipEl.style.top = y + 'px';
        },

        hideTooltip: function () {
            if (this.tooltipEl) this.tooltipEl.style.display = 'none';
        },

        escapeHtml: function (str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        onZoneClick: function (zoneId) {
            if (this.drawing) {
                // If currently drawing a new zone, don't switch.
                return;
            }

            if (this.editingId !== null && this.editingId !== zoneId && this.hasUnsavedShapes()) {
                if (!confirm('Discard unsaved changes to the current zone?')) {
                    return;
                }
            }

            this.$wire.loadZone(zoneId);
        },

        hasUnsavedShapes: function () {
            // Lightweight dirty check — true if drawn polygons or staged zips exist
            // that haven't been persisted (we can't easily compare to original).
            return this.polygonOverlays.length > 0 || this.zipCodes.length > 0;
        },

        beginEdit: function (zoneId, definition) {
            this.hideTooltip();
            this.hydrating = true;
            this.detachNotice = '';
            // Tear down any prior editing overlays (bumps editToken).
            this.tearDownEditing();
            const token = this.editToken;

            // If we were already editing a different zone, that zone's read-only
            // overlay was hidden — bring it back before hiding the new one,
            // otherwise it'd stay invisible (and unclickable) forever.
            this.restoreAllReadonlyOverlays();

            this.editingId = zoneId;
            this.editing = true;

            // Hide the read-only overlay for the zone being edited (we'll
            // render it as editable shapes instead so the user can grab handles).
            if (zoneId !== null && this.zoneOverlays[zoneId]) {
                this.zoneOverlays[zoneId].overlays.forEach(o => o.setMap(null));
            }

            this.applyDimming();

            // Hydrate editable shapes from definition.
            const bounds = new google.maps.LatLngBounds();
            let any = false;
            const polygons = (definition && definition.polygons) || [];
            const zipCodes = (definition && definition.zip_codes) || [];

            polygons.forEach(geojson => {
                const geometry = typeof geojson === 'string' ? JSON.parse(geojson) : geojson;
                this.geometryToRingSets(geometry).forEach(rings => {
                    const paths = this.ringSetToPaths(rings, bounds);
                    any = any || paths.length > 0;
                    const polygon = new google.maps.Polygon({
                        ...this.editablePolygonStyle('#2563eb'),
                        paths,
                    });
                    polygon.setMap(this.map);
                    this.attachEditablePolygonHandlers(polygon);
                    this.polygonOverlays.push(polygon);
                });
            });

            this.zipCodes = [];
            this.zipOverlays = {};
            this.polygonCount = this.polygonOverlays.length;

            if (zipCodes.length > 0) {
                this.hydrateZips(zipCodes, bounds).then(() => {
                    if (token !== this.editToken) return;
                    if (any || !bounds.isEmpty()) this.map.fitBounds(bounds, 64);
                    this.hydrating = false;
                });
            } else {
                if (any) this.map.fitBounds(bounds, 64);
                this.hydrating = false;
            }
        },

        markDirty: function () {
            if (this.hydrating) return;
            // Avoid spamming Livewire roundtrips on every polygon drag — only
            // sync when we actually transition false → true.
            if (this.$wire.isDirty) return;
            this.$wire.set('isDirty', true);
        },

        hydrateZips: async function (zips, bounds) {
            const token = this.editToken;
            for (const zip of zips) {
                if (token !== this.editToken) return;
                try {
                    const response = await fetch(`${this.zipSearchUrl}?q=${encodeURIComponent(zip)}`, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) continue;
                    const rows = await response.json();
                    const exact = rows.find(r => r.zip === zip);
                    if (!exact) continue;
                    const geometry = typeof exact.geojson === 'string' ? JSON.parse(exact.geojson) : exact.geojson;
                    const overlays = this.renderEditableZip(geometry, bounds);
                    // The edit ended while we were fetching/rendering — discard these
                    // overlays so they don't linger on the map until a page refresh.
                    if (token !== this.editToken) {
                        overlays.forEach(o => o.setMap(null));
                        return;
                    }
                    this.attachZipHandlers(zip, overlays);
                    this.zipOverlays[zip] = overlays;
                    this.zipCodes.push(zip);
                } catch (e) {
                    // Ignore individual zip hydration failures; the saved record
                    // is still authoritative server-side.
                }
            }
            if (token !== this.editToken) return;
            this.syncEditingState();
        },

        renderEditableZip: function (geometry, bounds) {
            const overlays = [];
            this.geometryToRingSets(geometry).forEach(rings => {
                const paths = this.ringSetToPaths(rings, bounds);
                const polygon = new google.maps.Polygon({ ...this.zipStyle(), paths });
                polygon.setMap(this.map);
                overlays.push(polygon);
            });
            return overlays;
        },

        attachZipHandlers: function (zip, overlays) {
            // Stored so detach/remove can drop them. A detached overlay that kept its
            // click handler would still select — and make editable — whatever the same
            // ZIP resolves to if it is re-added later.
            this.zipClickListeners[zip] = overlays.map(
                o => o.addListener('click', () => this.selectZip(zip))
            );
        },

        releaseZipListeners: function (zip) {
            (this.zipListeners[zip] || []).forEach(l => google.maps.event.removeListener(l));
            (this.zipClickListeners[zip] || []).forEach(l => google.maps.event.removeListener(l));
            delete this.zipListeners[zip];
            delete this.zipClickListeners[zip];
        },

        endEdit: function () {
            this.tearDownEditing();
            this.editingId = null;
            this.editing = false;
            this.drawing = false;
            if (this.drawingManager) this.drawingManager.setDrawingMode(null);

            this.restoreAllReadonlyOverlays();
            this.removeDimming();
        },

        restoreAllReadonlyOverlays: function () {
            Object.values(this.zoneOverlays).forEach(entry => {
                entry.overlays.forEach(o => o.setMap(this.map));
            });
        },

        tearDownEditing: function () {
            // Invalidate any in-flight zip hydration so it can't repaint after this.
            this.editToken++;

            this.polygonOverlays.forEach(p => p.setMap(null));
            this.polygonOverlays = [];

            Object.keys(this.zipOverlays).forEach(z => this.releaseZipListeners(z));
            Object.values(this.zipListeners).flat().forEach(l => google.maps.event.removeListener(l));
            Object.values(this.zipClickListeners).flat().forEach(l => google.maps.event.removeListener(l));
            this.zipListeners = {};
            this.zipClickListeners = {};
            this.selectedZip = null;
            this.detachNotice = '';

            Object.values(this.zipOverlays).flat().forEach(o => o.setMap(null));
            this.zipOverlays = {};
            this.zipCodes = [];
            this.zipQuery = '';
            this.zipError = '';
            this.polygonCount = 0;
        },

        removeZoneOverlay: function (zoneId) {
            const entry = this.zoneOverlays[zoneId];
            if (!entry) return;
            entry.listeners.forEach(l => google.maps.event.removeListener(l));
            entry.overlays.forEach(o => o.setMap(null));
            delete this.zoneOverlays[zoneId];
        },

        applyDimming: function () {
            Object.entries(this.zoneOverlays).forEach(([id, entry]) => {
                const isEditing = parseInt(id, 10) === this.editingId;
                const opacity = isEditing ? 0 : 0.15;
                entry.overlays.forEach(o => {
                    o.setOptions({
                        fillOpacity: opacity,
                        strokeOpacity: isEditing ? 0 : 0.4,
                        clickable: !isEditing,
                    });
                });
            });
        },

        removeDimming: function () {
            Object.values(this.zoneOverlays).forEach(entry => {
                entry.overlays.forEach(o => o.setOptions({
                    fillOpacity: 0.25,
                    strokeOpacity: 0.9,
                    clickable: true,
                }));
            });
        },

        readonlyZoneStyle: function (color) {
            return {
                strokeColor: color,
                strokeWeight: 2,
                strokeOpacity: 0.9,
                fillColor: color,
                fillOpacity: 0.25,
                clickable: true,
                editable: false,
                draggable: false,
            };
        },

        editablePolygonStyle: function (color) {
            return {
                strokeColor: color,
                strokeWeight: 2,
                fillColor: color,
                fillOpacity: 0.3,
                clickable: true,
                editable: true,
                draggable: false,
            };
        },

        zipStyle: function () {
            return {
                strokeColor: '#059669',
                strokeWeight: 2,
                fillColor: '#059669',
                fillOpacity: 0.2,
                clickable: true,
            };
        },

        attachEditablePolygonHandlers: function (polygon) {
            // Path events fire when the admin drags a vertex, adds one between
            // two existing vertices, or right-clicks to remove one. Every ring is
            // wired — a detached ZIP can carry interior rings.
            polygon.getPaths().forEach(path => {
                ['set_at', 'insert_at', 'remove_at'].forEach(evt => {
                    google.maps.event.addListener(path, evt, () => this.syncEditingState());
                });
            });
        },

        toggleDrawing: function () {
            if (!this.editing || !this.drawingManager) return;
            if (this.drawing) {
                this.drawingManager.setDrawingMode(null);
                this.drawing = false;
            } else {
                this.drawingManager.setDrawingMode(google.maps.drawing.OverlayType.POLYGON);
                this.drawing = true;
            }
        },

        clearEditing: function () {
            if (!confirm('Remove all polygons and ZIPs from this zone?')) return;
            this.polygonOverlays.forEach(p => p.setMap(null));
            this.polygonOverlays = [];

            Object.keys(this.zipOverlays).forEach(z => this.releaseZipListeners(z));
            Object.values(this.zipListeners).flat().forEach(l => google.maps.event.removeListener(l));
            Object.values(this.zipClickListeners).flat().forEach(l => google.maps.event.removeListener(l));
            this.zipListeners = {};
            this.zipClickListeners = {};
            this.selectedZip = null;
            this.detachNotice = '';

            Object.values(this.zipOverlays).flat().forEach(o => o.setMap(null));
            this.zipOverlays = {};
            this.zipCodes = [];
            this.syncEditingState();
        },

        // === ZIP code workflow ===

        commitZipInput: async function () {
            const raw = (this.zipQuery || '').trim();
            this.zipQuery = '';
            this.zipError = '';
            if (raw === '') return;

            const allTokens = raw.split(/[\s,]+/).filter(Boolean);
            const validTokens = allTokens.filter(t => /^\d{5}$/.test(t));
            const malformed = allTokens.filter(t => !/^\d{5}$/.test(t));
            const notFound = [];

            const bounds = new google.maps.LatLngBounds();
            for (const token of validTokens) {
                const result = await this.addZip(token, bounds);
                if (result === 'not_found' || result === 'error') notFound.push(token);
            }

            const messages = [];
            if (malformed.length) messages.push(`Not a valid ZIP: ${malformed.join(', ')}`);
            if (notFound.length) messages.push(`No boundary found for: ${notFound.join(', ')}`);
            this.zipError = messages.join(' · ');

            if (!bounds.isEmpty()) this.map.fitBounds(bounds, 32);
            this.syncEditingState();
        },

        addZip: async function (token, bounds) {
            if (this.zipCodes.includes(token)) return 'duplicate';

            let response;
            try {
                response = await fetch(`${this.zipSearchUrl}?q=${encodeURIComponent(token)}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
            } catch (e) {
                return 'error';
            }
            if (!response.ok) return 'error';
            const rows = await response.json();
            if (!rows.length) return 'not_found';

            const exact = rows.find(r => r.zip === token);
            if (!exact) return 'not_found';
            if (this.zipCodes.includes(exact.zip)) return 'duplicate';

            const geometry = typeof exact.geojson === 'string' ? JSON.parse(exact.geojson) : exact.geojson;
            const overlays = this.renderEditableZip(geometry, bounds);
            const zip = exact.zip;
            this.attachZipHandlers(zip, overlays);
            this.zipOverlays[zip] = overlays;
            this.zipCodes.push(zip);
            return 'added';
        },

        handleZipPaste: function (event) {
            event.preventDefault();
            const pasted = (event.clipboardData || window.clipboardData).getData('text') || '';
            this.zipQuery = (this.zipQuery || '') + pasted;
            this.commitZipInput();
        },

        handleZipBackspace: function () {
            if (this.zipQuery !== '' || this.zipCodes.length === 0) return;
            this.removeZip(this.zipCodes[this.zipCodes.length - 1]);
        },

        selectZip: function (zip) {
            if (this.drawing) return;
            if (this.selectedZip === zip) return;

            const overlays = this.zipOverlays[zip];
            if (!overlays) return;

            // `editable` materialises a drag handle per vertex plus a midpoint handle per
            // edge, synchronously. Raw TIGER ZCTAs are unsimplified and coastal ones run to
            // five figures, which freezes the tab. Refuse with an explanation instead.
            const vertices = overlays.reduce(
                (n, o) => n + o.getPaths().getArray().reduce((m, path) => m + path.getLength(), 0),
                0
            );
            if (vertices > this.zipEditVertexCap) {
                this.detachNotice = `ZIP ${zip} has ${vertices.toLocaleString()} boundary points — too detailed to edit directly. Draw a replacement boundary and remove the ZIP instead.`;
                return;
            }

            if (this.selectedZip) this.deselectZip();

            this.selectedZip = zip;
            this.detachNotice = '';
            this.zipListeners[zip] = [];
            overlays.forEach(o => {
                o.setOptions(this.editablePolygonStyle('#059669'));
                o.getPaths().forEach(path => {
                    ['set_at', 'insert_at', 'remove_at'].forEach(evt => {
                        this.zipListeners[zip].push(
                            google.maps.event.addListener(path, evt, () => this.detachZip(zip))
                        );
                    });
                });
            });
        },

        deselectZip: function () {
            const zip = this.selectedZip;
            if (!zip) return;

            // Only the path listeners go — the overlay is still a ZIP, so its click
            // handler must survive for the next selection.
            (this.zipListeners[zip] || []).forEach(l => google.maps.event.removeListener(l));
            delete this.zipListeners[zip];

            // zipStyle() carries no `editable` key and setOptions() applies only the keys
            // given, so this must be explicit or the handles outlive the selection.
            const overlays = this.zipOverlays[zip];
            if (overlays) overlays.forEach(o => o.setOptions({ ...this.zipStyle(), editable: false }));

            this.selectedZip = null;
        },

        detachZip: function (zip) {
            const overlays = this.zipOverlays[zip];
            if (!overlays) return;

            this.releaseZipListeners(zip);
            delete this.zipOverlays[zip];
            this.zipCodes = this.zipCodes.filter(z => z !== zip);
            if (this.selectedZip === zip) this.selectedZip = null;

            overlays.forEach(o => {
                o.setOptions(this.editablePolygonStyle('#2563eb'));
                this.attachEditablePolygonHandlers(o);
                this.polygonOverlays.push(o);
            });

            this.detachNotice = `ZIP ${zip} is now a custom boundary — its ZIP chip was removed.`;
            this.syncEditingState();
        },

        removeZip: function (zip) {
            this.releaseZipListeners(zip);
            if (this.selectedZip === zip) this.selectedZip = null;

            const overlays = this.zipOverlays[zip] || [];
            overlays.forEach(o => o.setMap(null));
            delete this.zipOverlays[zip];
            this.zipCodes = this.zipCodes.filter(z => z !== zip);
            this.syncEditingState();
        },

        // === Geometry helpers ===

        // One entry per polygon, each a full ring set: [exterior, ...holes]. Interior rings
        // are kept because a detached ZIP's client-side shape becomes the stored boundary
        // (DEV-2557) — dropping a hole would silently widen the zone.
        geometryToRingSets: function (geometry) {
            if (!geometry) return [];
            if (geometry.type === 'Polygon') return [geometry.coordinates];
            if (geometry.type === 'MultiPolygon') return geometry.coordinates;
            return [];
        },

        ringSetToPaths: function (rings, bounds) {
            return rings.map(ring => ring.map(([lng, lat]) => {
                const pt = new google.maps.LatLng(lat, lng);
                if (bounds) bounds.extend(pt);
                return pt;
            }));
        },

        // Drop degenerate shapes — a valid polygon ring needs >=3 distinct vertices; a
        // point/line 500s ST_GeomFromGeoJSON and aborts the save.
        closeRing: function (path) {
            const ring = path.getArray().map(latLng => [latLng.lng(), latLng.lat()]);
            if (ring.length < 3) return null;
            const first = ring[0];
            const last = ring[ring.length - 1];
            if (first[0] !== last[0] || first[1] !== last[1]) ring.push([...first]);
            return ring.length < 4 ? null : ring;
        },

        polygonToGeoJson: function (polygon) {
            // Path 0 is the exterior, the rest are holes. A detached ZIP can carry holes,
            // and after detach this output IS the stored boundary (DEV-2557).
            const rings = polygon.getPaths().getArray().map(path => this.closeRing(path));
            if (rings.length === 0 || !rings[0]) return null;
            return { type: 'Polygon', coordinates: rings.filter(Boolean) };
        },

        syncEditingState: function () {
            this.polygonCount = this.polygonOverlays.length;
            const polygons = this.polygonOverlays
                .map(p => this.polygonToGeoJson(p))
                .filter(Boolean);
            const payload = { polygons, zip_codes: [...this.zipCodes] };
            this.$wire.set('data.definition', payload, false);
            this.markDirty();
        },
    };
};

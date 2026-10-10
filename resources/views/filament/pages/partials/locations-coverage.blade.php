@php
    $map = $this->locationMap();
    $polygonPoints = \App\Support\Prototype\LocationCoverageMap::pointCount($coveragePolygon);
    $locationPolygonPoints = \App\Support\Prototype\LocationCoverageMap::pointCount($locationForm['coverage_polygon'] ?? null);
@endphp

<x-filament::section
    id="locations-coverage"
    heading="Locations & Coverage"
    description="Offices and storage yards for this company, and the area they cover. Stored only in this prototype."
>
    <x-slot name="afterHeader">
        @unless ($showLocationForm)
            <x-filament::button size="sm" icon="heroicon-m-plus" wire:click="startCreate">
                Add location
            </x-filament::button>
        @endunless
    </x-slot>

    <div class="space-y-6">
        <div>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-950">
                @if ($map['svg']['pins'] === [])
                    <div class="flex h-56 items-center justify-center px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        Add a location with coordinates to place a pin.
                    </div>
                @else
                    <svg viewBox="0 0 {{ $map['svg']['width'] }} {{ $map['svg']['height'] }}" class="h-[340px] w-full" role="img" aria-label="Locations and coverage">
                        <rect width="100%" height="100%" fill="#f3f4f6"></rect>
                        @for ($g = 0; $g < 8; $g++)
                            <line x1="{{ $g * 100 }}" y1="0" x2="{{ $g * 100 }}" y2="{{ $map['svg']['height'] }}" stroke="#e5e7eb" stroke-width="1"></line>
                        @endfor
                        @foreach ($map['svg']['circles'] as $circle)
                            <ellipse cx="{{ $circle['cx'] }}" cy="{{ $circle['cy'] }}" rx="{{ $circle['rx'] }}" ry="{{ $circle['ry'] }}" fill="{{ $circle['color'] }}" fill-opacity="0.12" stroke="{{ $circle['color'] }}" stroke-width="2" stroke-dasharray="6 4"></ellipse>
                        @endforeach
                        @foreach ($map['svg']['polygons'] as $polygon)
                            <polygon points="{{ $polygon['points'] }}" fill="{{ $polygon['color'] }}" fill-opacity="0.16" stroke="{{ $polygon['color'] }}" stroke-width="2"></polygon>
                        @endforeach
                        @foreach ($map['svg']['pins'] as $pin)
                            <g transform="translate({{ $pin['x'] }}, {{ $pin['y'] }})">
                                <title>{{ $pin['label'] }}</title>
                                @if ($pin['pin'] === 'square')
                                    <rect x="-7" y="-22" width="14" height="14" rx="2" fill="{{ $pin['color'] }}" stroke="#fff" stroke-width="2"></rect>
                                @elseif ($pin['pin'] === 'circle')
                                    <circle cx="0" cy="-16" r="8" fill="{{ $pin['color'] }}" stroke="#fff" stroke-width="2"></circle>
                                @else
                                    <path d="M0 0 C-7 -10 -8 -16 -8 -20 a8 8 0 1 1 16 0 c0 4 -1 10 -8 20 z" fill="{{ $pin['color'] }}" stroke="#fff" stroke-width="1.5"></path>
                                @endif
                            </g>
                        @endforeach
                    </svg>
                @endif

                @if ($map['hasKey'])
                    <div
                        wire:ignore
                        class="border-t border-gray-200 dark:border-white/10"
                        x-data="clarityLocationMap(@js($map))"
                    >
                        <div x-ref="map" class="h-[340px] w-full"></div>
                    </div>
                @endif
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-600 dark:text-gray-300">
                @foreach (\App\Enums\OrgLocationType::cases() as $type)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block size-2.5 rounded-full" style="background-color: {{ $type->color() }}"></span>
                        {{ $type->label() }}
                    </span>
                @endforeach
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-2.5 w-4 rounded-sm border border-dashed border-[#FF6666] bg-[#FF6666]/10"></span>
                    Coverage zone
                </span>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($map['hasKey'])
                    Pins and the coverage zone use the map. Drawing a polygon uses the same Google Maps drawing tools as zones.
                @else
                    Pins and the coverage outline use saved coordinates. Map tiles and polygon drawing need a Google Maps key, so this preview stays available without one.
                @endif
            </p>
        </div>

        <div id="coverage-zone" class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Coverage zone</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Choose whether one zone covers the whole company, or each location has its own.
                    </p>
                </div>
                <p id="coverage-summary" class="rounded-lg bg-gray-50 px-3 py-2 text-sm font-medium text-gray-800 dark:bg-white/5 dark:text-gray-100">
                    {{ $map['summary'] }}
                </p>
            </div>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <fieldset>
                    <legend class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Applies to</legend>
                    <label class="mt-2 flex items-start gap-2 text-sm text-gray-800 dark:text-gray-100">
                        <input type="radio" class="mt-1" wire:model.live="coverageScope" value="company">
                        <span><span class="font-medium">Company-wide zone</span><span class="mt-0.5 block text-gray-500 dark:text-gray-400">One area for every location.</span></span>
                    </label>
                    <label class="mt-2 flex items-start gap-2 text-sm text-gray-800 dark:text-gray-100">
                        <input type="radio" class="mt-1" wire:model.live="coverageScope" value="location">
                        <span><span class="font-medium">Per location</span><span class="mt-0.5 block text-gray-500 dark:text-gray-400">Each location keeps its own radius or polygon.</span></span>
                    </label>
                </fieldset>

                <fieldset>
                    <legend class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Company-wide shape</legend>
                    <label class="mt-2 flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
                        <input type="radio" wire:model="coverageMode" value="radius">
                        Radius around a location
                    </label>
                    <label class="mt-2 flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
                        <input type="radio" wire:model="coverageMode" value="polygon">
                        Drawn polygon
                    </label>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        The map uses the shape selected here when coverage applies company-wide. The other shape stays saved.
                    </p>
                </fieldset>
            </div>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label for="coverage-center" class="text-sm font-medium text-gray-950 dark:text-white">Center location</label>
                    <select id="coverage-center" wire:model="coverageCenterLocationId" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        <option value="">Select a location</option>
                        @foreach ($map['rows'] as $row)
                            <option value="{{ $row['id'] }}">{{ $row['name'] }}</option>
                        @endforeach
                    </select>
                    @error('coverageCenterLocationId')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="coverage-miles" class="text-sm font-medium text-gray-950 dark:text-white">Radius (miles)</label>
                    <input id="coverage-miles" type="number" min="0.1" max="500" step="0.1" wire:model="coverageRadiusMiles" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                    @error('coverageRadiusMiles')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-filament::button size="sm" color="gray" outlined type="button" x-on:click="window.clarityLocationMapApi ? window.clarityLocationMapApi.begin('company') : $wire.mapUnavailable()">
                    Draw polygon
                </x-filament::button>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($polygonPoints > 0)
                        Drawn area ({{ $polygonPoints }} points)
                    @else
                        No polygon saved
                    @endif
                </span>
                <x-filament::button size="sm" wire:click="saveCoverage">
                    Save coverage
                </x-filament::button>
            </div>
            @error('coverageMode')
                <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @if ($showLocationForm)
            <form id="location-form" wire:submit="saveLocation" class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $editingLocationId ? 'Edit location' : 'Add location' }}
                </h3>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="location-name" class="text-sm font-medium text-gray-950 dark:text-white">Name</label>
                        <input id="location-name" wire:model="locationForm.name" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        @error('locationForm.name')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="location-type" class="text-sm font-medium text-gray-950 dark:text-white">Type</label>
                        <select id="location-type" wire:model="locationForm.type" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                            @foreach (\App\Enums\OrgLocationType::cases() as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Only one Main Office is allowed. Storage facilities and satellite offices can repeat.</p>
                        @error('locationForm.type')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label for="location-address" class="text-sm font-medium text-gray-950 dark:text-white">Address</label>
                        <input id="location-address" wire:model="locationForm.address" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        @error('locationForm.address')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="location-phone" class="text-sm font-medium text-gray-950 dark:text-white">Phone <span class="font-normal text-gray-500">(optional)</span></label>
                        <input id="location-phone" wire:model="locationForm.phone" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                    </div>
                    <div>
                        <label for="location-notes" class="text-sm font-medium text-gray-950 dark:text-white">Notes <span class="font-normal text-gray-500">(optional)</span></label>
                        <input id="location-notes" wire:model="locationForm.notes" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                    </div>
                    <div>
                        <label for="location-lat" class="text-sm font-medium text-gray-950 dark:text-white">Latitude <span class="font-normal text-gray-500">(optional pin)</span></label>
                        <input id="location-lat" wire:model="locationForm.latitude" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                        @error('locationForm.latitude')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="location-lng" class="text-sm font-medium text-gray-950 dark:text-white">Longitude <span class="font-normal text-gray-500">(optional pin)</span></label>
                        <input id="location-lng" wire:model="locationForm.longitude" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                    </div>
                </div>

                @if ($coverageScope === 'location')
                    <div class="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">Coverage for this location</p>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-800 dark:text-gray-100">
                            <label class="inline-flex items-center gap-2"><input type="radio" wire:model="locationForm.coverage_mode" value="">None</label>
                            <label class="inline-flex items-center gap-2"><input type="radio" wire:model="locationForm.coverage_mode" value="radius">Radius</label>
                            <label class="inline-flex items-center gap-2"><input type="radio" wire:model="locationForm.coverage_mode" value="polygon">Polygon</label>
                        </div>
                        <div class="mt-3 max-w-xs">
                            <label for="location-miles" class="text-sm font-medium text-gray-950 dark:text-white">Radius (miles)</label>
                            <input id="location-miles" type="number" min="0.1" max="500" step="0.1" wire:model="locationForm.coverage_radius_miles" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                            @error('locationForm.coverage_radius_miles')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <x-filament::button size="sm" color="gray" outlined type="button" x-on:click="window.clarityLocationMapApi ? window.clarityLocationMapApi.begin('location') : $wire.mapUnavailable()">
                                Draw polygon
                            </x-filament::button>
                            <span class="text-sm text-gray-500">
                                @if ($locationPolygonPoints > 0)
                                    Drawn area ({{ $locationPolygonPoints }} points). Save the location to keep it.
                                @else
                                    No polygon on this location yet.
                                @endif
                            </span>
                        </div>
                        @error('locationForm.coverage_mode')
                            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @else
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        This location is included in the company-wide zone. Switch coverage to “Per location” to set a radius or polygon on this address.
                    </p>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button type="submit" size="sm">Save location</x-filament::button>
                    <x-filament::button type="button" size="sm" color="gray" outlined wire:click="cancelLocation">Cancel</x-filament::button>
                </div>
            </form>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table id="locations-table" class="w-full min-w-[720px] text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Address</th>
                        <th class="px-3 py-2 font-medium">Phone</th>
                        <th class="px-3 py-2 font-medium">Coverage</th>
                        <th class="px-3 py-2 font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($map['rows'] as $row)
                        <tr>
                            <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                            <td class="px-3 py-3 text-gray-700 dark:text-gray-200">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="inline-block size-2.5 rounded-full" style="background-color: {{ $row['color'] }}"></span>
                                    {{ $row['type_label'] }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-gray-700 dark:text-gray-200">{{ $row['address'] }}</td>
                            <td class="px-3 py-3 text-gray-700 dark:text-gray-200">{{ $row['phone'] ?: '—' }}</td>
                            <td class="px-3 py-3 text-gray-700 dark:text-gray-200">{{ $row['coverage'] }}</td>
                            <td class="px-3 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <x-filament::button size="xs" color="gray" outlined wire:click="startEdit({{ $row['id'] }})">Edit</x-filament::button>
                                    <x-filament::button size="xs" color="danger" outlined wire:click="deleteLocation({{ $row['id'] }})" wire:confirm="Delete {{ $row['name'] }}?">Delete</x-filament::button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-gray-500">No locations yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament::section>

@once
    <script>
        window.clarityLocationMap = function (initial) {
            return {
                state: initial,
                map: null,
                drawingManager: null,
                overlays: [],
                drawTarget: 'company',

                init() {
                    window.clarityLocationMapApi = this;
                    this.$wire.on('locations-refresh', (event) => {
                        this.state = event?.state ?? event ?? this.state;
                        this.renderOverlays();
                    });
                    this.boot();
                },

                async boot() {
                    if (!this.state.hasKey) {
                        return;
                    }

                    const start = Date.now();
                    while (!(window.google && window.google.maps && typeof window.google.maps.importLibrary === 'function')) {
                        if (Date.now() - start > 8000) {
                            return;
                        }
                        await new Promise((resolve) => setTimeout(resolve, 200));
                    }

                    try {
                        const [{ Map }, drawing] = await Promise.all([
                            google.maps.importLibrary('maps'),
                            google.maps.importLibrary('drawing'),
                        ]);
                        const el = this.$refs.map;
                        if (!el) {
                            return;
                        }

                        this.map = new Map(el, {
                            center: { lat: 39.7684, lng: -86.1581 },
                            zoom: 10,
                            mapTypeControl: false,
                            streetViewControl: false,
                            fullscreenControl: true,
                            gestureHandling: 'cooperative',
                        });

                        this.drawingManager = new drawing.DrawingManager({
                            drawingMode: null,
                            drawingControl: false,
                            polygonOptions: {
                                fillColor: '#FF6666',
                                fillOpacity: 0.15,
                                strokeColor: '#FF6666',
                                strokeWeight: 2,
                                editable: false,
                            },
                        });
                        this.drawingManager.setMap(this.map);

                        google.maps.event.addListener(this.drawingManager, 'polygoncomplete', (polygon) => {
                            this.drawingManager.setDrawingMode(null);
                            const ring = polygon.getPath().getArray().map((point) => [point.lng(), point.lat()]);
                            if (ring.length >= 3) {
                                ring.push(ring[0]);
                                const geo = { type: 'Polygon', coordinates: [ring] };
                                if (this.drawTarget === 'location') {
                                    this.$wire.captureLocationPolygon(geo);
                                } else {
                                    this.$wire.captureCompanyPolygon(geo);
                                }
                            }
                            polygon.setMap(null);
                        });

                        this.renderOverlays();
                    } catch (error) {
                        console.error('configuration locations map failed', error);
                    }
                },

                begin(target) {
                    this.drawTarget = target;
                    if (!this.drawingManager) {
                        this.$wire.mapUnavailable();
                        return;
                    }
                    this.drawingManager.setDrawingMode(google.maps.drawing.OverlayType.POLYGON);
                },

                renderOverlays() {
                    if (!this.map) {
                        return;
                    }

                    this.overlays.forEach((overlay) => overlay.setMap(null));
                    this.overlays = [];
                    const bounds = new google.maps.LatLngBounds();
                    let any = false;

                    (this.state.markers || []).forEach((marker) => {
                        any = true;
                        bounds.extend({ lat: marker.lat, lng: marker.lng });
                        this.overlays.push(new google.maps.Marker({
                            map: this.map,
                            position: { lat: marker.lat, lng: marker.lng },
                            title: marker.name + ' (' + marker.type + ')',
                            icon: {
                                path: marker.pin === 'square'
                                    ? 'M -1,-1 1,-1 1,1 -1,1 z'
                                    : (marker.pin === 'circle' ? google.maps.SymbolPath.CIRCLE : 'M 0,0 C -2,-6 -2,-10 0,-14 C 2,-10 2,-6 0,0 z'),
                                scale: marker.pin === 'pin' ? 1.4 : 8,
                                fillColor: marker.color,
                                fillOpacity: 1,
                                strokeColor: '#ffffff',
                                strokeWeight: 2,
                            },
                        }));
                    });

                    (this.state.shapes || []).forEach((shape) => {
                        if (shape.kind === 'circle') {
                            any = true;
                            bounds.extend({ lat: shape.lat, lng: shape.lng });
                            this.overlays.push(new google.maps.Circle({
                                map: this.map,
                                center: { lat: shape.lat, lng: shape.lng },
                                radius: shape.radiusMiles * 1609.344,
                                fillColor: shape.color,
                                fillOpacity: 0.12,
                                strokeColor: shape.color,
                                strokeWeight: 2,
                            }));
                        }
                        if (shape.kind === 'polygon') {
                            const path = (shape.path || []).map((point) => {
                                any = true;
                                bounds.extend({ lat: point[1], lng: point[0] });
                                return { lat: point[1], lng: point[0] };
                            });
                            this.overlays.push(new google.maps.Polygon({
                                map: this.map,
                                paths: path,
                                fillColor: shape.color,
                                fillOpacity: 0.16,
                                strokeColor: shape.color,
                                strokeWeight: 2,
                            }));
                        }
                    });

                    if (any) {
                        this.map.fitBounds(bounds, 32);
                    }
                },
            };
        };
    </script>
@endonce

<x-filament-panels::page>
    <div class="flex flex-col lg:grid lg:grid-cols-4 xl:grid-cols-5 gap-4">
        <div
            x-cloak
            x-ref="sidebarFilters"
            x-init="$store.sidebar.close()"
            class="filament-tables-filters-sidebar flex flex-col gap-4"
        >
            <x-filament::section collapsible="true" class="sidebar-filter-section-wrapper">
                <x-slot name="heading">
                    <span class="inline lg:hidden">
                        Filters
                    </span>
                </x-slot>

                <x-slot name="description">
                    <span class="inline lg:hidden">
                        [Click to <span x-text="isCollapsed ? 'Expand' : 'Collapse'"></span>]
                    </span>
                </x-slot>

                <x-filament-tables::filters
                    :apply-action="$this->getFiltersApplyAction()"
                    :form="$this->getFiltersForm()"
                />
            </x-filament::section>
        </div>

        <x-filament::section class="w-full lg:col-span-3 xl:col-span-4">
            <div
                x-ignore
                x-load
                x-load-src="{{ Vite::asset('resources/js/components/kit-user-path.js')}}"
                x-data="window.filamentKitUserPath($refs.map)"
                wire:ignore
                class="relative"
            >
                <div id="map-loading-indicator" class="flex absolute z-40 w-full h-full items-center justify-center" data-class="!hidden">
                    <x-filament::loading-indicator
                        class="absolute w-10 lg:w-16 h-10 lg:h-16"
                    />
                </div>

                <div id="init-loading-indicator" class="hidden absolute z-30 w-full h-full items-center justify-center" data-class="!hidden">
                    <div class="absolute left-0 top-0 w-full h-full bg-gray-800 opacity-60"></div>
                    <p class="text-gray-100 text-lg font-semibold z-40">
                        Select a Kit User to Begin
                    </p>
                </div>

                <div id="geohash-loading-indicator" class="hidden absolute z-40 w-full h-full items-end justify-center" data-class="!flex">
                    <div class="inline-flex items-center justify-center gap-2 text-xs lg:text-sm text-gray-100 rounded-md bg-gray-800 py-1 px-1.5 mb-4">
                        <x-filament::loading-indicator
                            class="w-6 lg:w-8 h-6 lg:h-8"
                        />
                        Loading GeoHash Layers...
                    </div>
                </div>

                <div id="staging-geohash-loading-indicator" class="hidden absolute z-40 w-full h-full items-end justify-center" data-class="!flex">
                    <div class="inline-flex items-center justify-center gap-2 text-xs lg:text-sm text-gray-100 rounded-md bg-gray-800 py-1 px-1.5 mb-4">
                        <x-filament::loading-indicator
                            class="w-6 lg:w-8 h-6 lg:h-8"
                        />
                        Loading Staging Hotlists...
                    </div>
                </div>

                <div id="hit-density-loading-indicator" class="hidden absolute z-40 w-full h-full items-end justify-center" data-class="!flex">
                    <div class="inline-flex items-center justify-center gap-2 text-xs lg:text-sm text-gray-100 rounded-md bg-gray-800 py-1 px-1.5 mb-4">
                        <x-filament::loading-indicator
                            class="w-6 lg:w-8 h-6 lg:h-8"
                        />
                        Loading Hit Density Layers...
                    </div>
                </div>

                <div
                    x-ref="map"
                    x-ignore
                    wire:ignore
                    class="relative w-full"
                    style="min-height: 65vh; z-index: 1 !important;"
                ></div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

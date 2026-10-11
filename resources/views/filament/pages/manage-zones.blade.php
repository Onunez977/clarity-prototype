<x-filament-panels::page>
    <div class="flex flex-col lg:grid lg:grid-cols-4 gap-4">
        <div class="flex flex-col gap-4 lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-2" x-data="{}">
                        <div class="flex items-center gap-2">
                            <span x-text="$wire.selectedZoneId ? 'Edit Zone' : 'New Zone'"></span>

                            <span
                                x-show="$wire.isDirty"
                                x-cloak
                                class="inline-flex items-center gap-1 rounded-full bg-warning-100 dark:bg-warning-500/20 px-2 py-0.5 text-xs font-medium text-warning-700 dark:text-warning-200"
                            >
                                <span class="size-1.5 rounded-full bg-warning-500"></span>
                                Unsaved
                            </span>
                        </div>

                        <button
                            type="button"
                            wire:click="startNewZone"
                            class="fi-btn fi-btn-size-sm fi-btn-color-primary inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-md bg-primary-600 text-white hover:bg-primary-500"
                        >
                            <x-filament::icon icon="heroicon-o-plus" class="w-4 h-4" />
                            New Zone
                        </button>
                    </div>
                </x-slot>

                <form wire:submit="save" class="space-y-4">
                    {{ $this->form }}

                    <div
                        x-data="{}"
                        class="sticky bottom-0 -mx-6 -mb-6 px-6 py-3 mt-2 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 rounded-b-xl"
                    >
                        <p
                            class="text-xs text-warning-700 dark:text-warning-200 mb-2"
                            x-show="$wire.isDirty"
                            x-cloak
                        >
                            ⚠ Click <strong x-text="$wire.selectedZoneId ? 'Save Changes' : 'Create Zone'"></strong> below to persist your edits.
                        </p>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::button type="submit" color="primary">
                                <span x-text="$wire.selectedZoneId ? 'Save Changes' : 'Create Zone'"></span>
                            </x-filament::button>

                            <x-filament::button
                                type="button"
                                color="gray"
                                wire:click="cancel"
                                x-show="$wire.selectedZoneId !== null"
                                x-cloak
                            >
                                Cancel
                            </x-filament::button>

                            @if ($this->selectedZoneId !== null)
                                <span>{{ $this->quickReassignAction }}</span>
                                <span class="ml-auto">{{ $this->deleteAction }}</span>
                            @endif
                        </div>
                    </div>
                </form>
            </x-filament::section>
        </div>

        <x-filament::section class="w-full lg:col-span-3">
            <div
                x-ignore
                x-load
                x-load-src="{{ Vite::asset('resources/js/components/zone-manager.js') }}"
                x-data="window.filamentZoneManager({
                    zonesUrl: @js(route('zones.all')),
                    zipSearchUrl: @js(route('zones.zip-search')),
                    noScanColor: @js(\App\Models\Zone::NO_SCAN_COLOR),
                })"
                wire:ignore
                class="relative"
            >
                <div id="zone-map-loading-indicator" class="flex absolute z-40 w-full h-full items-center justify-center" data-class="!hidden">
                    <x-filament::loading-indicator class="absolute w-10 lg:w-16 h-10 lg:h-16" />
                </div>

                <div
                    x-show="!editing"
                    x-cloak
                    class="mb-3 rounded-md border border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 px-3 py-2 text-xs text-gray-600 dark:text-gray-400"
                >
                    Click a zone on the map to edit it, or use <strong class="text-gray-900 dark:text-gray-100">New Zone</strong> to start a new boundary.
                </div>

                <div
                    x-show="editing && !drawing"
                    x-cloak
                    class="mb-3 rounded-md border border-warning-400 bg-warning-50 dark:border-warning-500 dark:bg-warning-500/10 px-3 py-2 text-xs text-warning-800 dark:text-warning-100"
                >
                    <strong>Define this zone's boundary</strong> by drawing one or more polygons on the map <em>and / or</em> adding ZIP codes below. Your changes aren't saved until you click <strong x-text="$wire.selectedZoneId ? 'Save Changes' : 'Create Zone'"></strong> on the left.
                </div>

                <div
                    x-show="drawing"
                    x-cloak
                    x-transition.opacity
                    class="clarity-chip mb-3 rounded-md border border-primary-400 px-3 py-2 text-xs dark:border-primary-500"
                >
                    <strong>Drawing:</strong> Click the map to drop each corner of the boundary &mdash; the cursor will not &ldquo;trace&rdquo; as you move. Double-click the final point to close the polygon, or click <em>Stop Drawing</em> to cancel. Drag any corner afterward to fine-tune it.
                </div>

                <div class="flex flex-wrap items-center gap-2 mb-3" x-show="editing" x-cloak>
                    <button
                        type="button"
                        class="fi-btn fi-btn-size-sm fi-btn-color-primary inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium rounded-md bg-primary-600 text-white hover:bg-primary-500"
                        x-on:click="toggleDrawing()"
                        x-bind:class="drawing ? '!bg-danger-600 hover:!bg-danger-500' : ''"
                    >
                        <span x-show="!drawing">+ Draw Polygon</span>
                        <span x-show="drawing">Stop Drawing</span>
                    </button>

                    <button
                        type="button"
                        class="fi-btn fi-btn-size-sm fi-btn-color-gray inline-flex items-center gap-1 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-200 hover:underline"
                        x-on:click="clearEditing()"
                    >
                        Clear shapes
                    </button>

                    <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="polygonCount"></span> polygon<span x-show="polygonCount !== 1">s</span>
                        &middot;
                        <span x-text="zipCodes.length"></span> ZIP<span x-show="zipCodes.length !== 1">s</span>
                    </span>
                </div>

                <div class="mb-3" x-show="editing" x-cloak>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                        ZIP codes
                    </label>

                    <div
                        class="fi-input flex flex-wrap items-center gap-1.5 min-h-[2.5rem] rounded-lg border bg-white px-2 py-1.5 text-sm shadow-sm focus-within:ring-1 dark:bg-gray-900"
                        x-bind:class="zipError
                            ? 'border-danger-500 focus-within:border-danger-500 focus-within:ring-danger-500'
                            : 'border-gray-300 dark:border-gray-700 focus-within:border-primary-500 focus-within:ring-primary-500'"
                        x-on:click="$refs.zipInput.focus()"
                    >
                        <template x-for="zip in zipCodes" :key="zip">
                            <span class="clarity-chip inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium">
                                <span x-text="zip"></span>
                                <button
                                    type="button"
                                    x-on:click.stop="removeZip(zip)"
                                    class="clarity-chip-btn"
                                    aria-label="Remove ZIP"
                                >&times;</button>
                            </span>
                        </template>

                        <input
                            type="text"
                            x-ref="zipInput"
                            x-model="zipQuery"
                            inputmode="numeric"
                            placeholder="Add ZIP code…"
                            class="flex-1 min-w-[8rem] border-0 bg-transparent p-0 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0 dark:text-gray-100 dark:placeholder-gray-500"
                            x-on:input="zipError = ''"
                            x-on:keydown.enter.prevent="commitZipInput()"
                            x-on:keydown.,.prevent="commitZipInput()"
                            x-on:keydown.backspace="handleZipBackspace()"
                            x-on:paste="handleZipPaste($event)"
                        />
                    </div>

                    <p
                        x-show="zipError"
                        x-cloak
                        x-text="zipError"
                        class="mt-1 text-xs text-danger-600 dark:text-danger-400"
                    ></p>

                    <p
                        x-show="detachNotice"
                        x-cloak
                        x-transition.opacity
                        x-text="detachNotice"
                        class="mt-1 text-xs text-gray-600 dark:text-gray-400"
                    ></p>

                    <p
                        x-show="! zipError"
                        class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                    >
                        Type a ZIP and press <kbd class="px-1 rounded bg-gray-100 dark:bg-gray-800">Enter</kbd> or <kbd class="px-1 rounded bg-gray-100 dark:bg-gray-800">,</kbd> to add it. Paste a list to add many at once.
                    </p>
                </div>

                <div
                    x-ref="map"
                    x-ignore
                    wire:ignore
                    class="relative w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-900"
                    style="height: 65vh; min-height: 500px; z-index: 1 !important;"
                ></div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

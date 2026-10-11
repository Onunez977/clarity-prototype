@php
    $hasMapComponent = false;
    $table = $this?->table ?? null;
@endphp

@if ($table)
    <div
        x-cloak
        x-ref="sidebarFilters"
        x-init="$store.sidebar.close()"
        class="filament-tables-filters-sidebar flex flex-col gap-4"
    >
        @foreach ($table->getBulkActions() ?? [] as $action)
            @if ($action instanceof \App\Filament\Actions\BulkMapAction && ! $hasMapComponent)
                @php
                    $hasMapComponent = true;
                @endphp

                @livewire('table-map-component', [
                    'model' => $action->getModel(),
                    'relationship' => $action->relationship,
                ], uniqid())
            @endif
        @endforeach

        @if ($table->getFiltersLayout() === \Filament\Tables\Enums\FiltersLayout::Hidden)
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
                    :apply-action="$table->getFiltersApplyAction()"
                    :form="$table->getFiltersForm()"
                />
            </x-filament::section>
        @endif
    </div>
@endif

@php
    $table = $this->table;
@endphp

@if ($table && $table->getFiltersLayout() === \Filament\Tables\Enums\FiltersLayout::Hidden)
    <div x-data="{ open: true }" class="hidden lg:block">
        <x-filament::button
            size="sm"
            color="gray"
            outlined="true"
            @click="open = ! open"
            x-init="$watch('open', value => $refs.sidebarFilters.classList.toggle('hidden'))"
        >
            <span x-text="open ? 'Close' : 'Open'"></span>
            Filters

            <x-slot name="badge">
                {{ $table->getActiveFiltersCount() ?? 0 }}
            </x-slot>
        </x-filament::button>
    </div>
@endif

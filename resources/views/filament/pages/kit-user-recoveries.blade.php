<x-filament-panels::page>
    <x-filament::section
        :collapsible="true"
        :collapsed="true"
        x-init="$store.sidebar.close()"
    >
        <x-slot name="heading">
            Filters

            <span x-show="isCollapsed" class="text-xs ml-2 text-gray-400">[Click to Expand]</span>
            <span x-show="! isCollapsed" class="text-xs ml-2 text-gray-400">[Click to Close]</span>
        </x-slot>

        <x-slot name="description">
            <div class="flex mt-1">
                @if (isset($kits[0]))
                    <x-filament::badge size="sm">
                        @if ($kits[0] === 'All Kits')
                            All Kit Users
                        @else
                            Kit Users ({{ \count($kits) }})
                        @endif
                    </x-filament::badge>
                @endif
                @if ($start_date || $end_date)
                    <x-filament::badge size="sm">
                        @if ($start_date && ! $end_date)
                            Recovery Date: starting at {{ prettyDatetime($start_date) }}
                        @elseif ($end_date && ! $start_date)
                            Recovery Date: ending at {{ prettyDatetime($end_date) }}
                        @else
                            Recovery Date: {{ prettyDatetime($start_date) }} - {{ prettyDatetime($end_date) }}
                        @endif
                    </x-filament::badge>
                @endif
            </div>
        </x-slot>

        <div class="filament-tables-filters-full">
            <x-filament-tables::filters
                :apply-action="$this->getFiltersApplyAction()"
                :form="$this->getFiltersForm()"
            />
        </div>
    </x-filament::section>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" style="--cols-default: repeat(1, minmax(0, 1fr));">
        <div>
            @livewire(\App\Filament\Widgets\KitUserRecoveries\LeaderboardTableWidget::class)
        </div>

        <div class="lg:col-span-2">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>

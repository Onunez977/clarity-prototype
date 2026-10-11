@php
    $board = $this->board();
    $visibleIds = array_column($board['rows'], 'id');
    $allVisibleSelected = $visibleIds !== [] && count(array_diff($visibleIds, array_map('intval', $selected))) === 0;
@endphp

<div id="opportunities-page" class="space-y-4">
    @if ($showTour)
        <div id="demo-tour" class="rounded-xl border border-[#FF6666]/30 bg-[#FF6666]/5 p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">Demo tour</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-200">
                        <li>Search <span class="font-medium">ABC1234</span>. The reason names an overnight cluster and says no check-in recorded.</li>
                        <li>Open the row for the score breakdown, address ranking, and the 0.1-mile map.</li>
                        <li>Dispatch it to Jordan Hale, high priority, overnight.</li>
                        <li>Reload. Dispatched stays on the row, and Unassigned only hides it.</li>
                        <li>Open Morning plan for the coverage outline and Jordan's straight-line stops.</li>
                    </ol>
                </div>
                <x-filament::button id="dismiss-tour" color="gray" size="sm" wire:click="dismissTour">Dismiss</x-filament::button>
            </div>
        </div>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-3">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ $board['counts']['total'] }} open · {{ $board['counts']['hot'] }} hot · {{ $board['counts']['warm'] }} warm · {{ $board['counts']['cool'] }} cool · {{ $board['counts']['unassigned'] }} unassigned.
            Recovery Likelihood is additive. Weights live in <span class="font-medium">RecoveryLikelihood</span>.
        </p>
        <div class="flex gap-2">
            <x-filament::button id="view-queue" size="sm" :color="$viewMode === 'queue' ? 'primary' : 'gray'" wire:click="$set('viewMode', 'queue')">Queue</x-filament::button>
            <x-filament::button id="view-plan" size="sm" :color="$viewMode === 'plan' ? 'primary' : 'gray'" wire:click="$set('viewMode', 'plan')">Morning plan</x-filament::button>
        </div>
    </div>

    @if ($viewMode === 'plan')
        @include('filament.pages.partials.opportunity-plan', ['board' => $board])
    @else
        <div class="rounded-xl border border-gray-200 p-3 dark:border-white/10">
            <div class="grid gap-3 md:grid-cols-4 xl:grid-cols-8">
                <label class="xl:col-span-2 block text-xs font-medium text-gray-600 dark:text-gray-300">
                    Plate, VIN, or order
                    <input id="opportunity-search" type="search" wire:model.live.debounce.300ms="q" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-950" placeholder="ABC1234">
                </label>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                    Client
                    <select id="filter-client" wire:model.live="client" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">All</option>
                        @foreach ($board['clients'] as $name)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                    Branch
                    <select id="filter-branch" wire:model.live="branch" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">All</option>
                        @foreach ($board['branches'] as $name)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                    Score band
                    <select id="filter-band" wire:model.live="band" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">All</option>
                        <option value="hot">Hot</option>
                        <option value="warm">Warm</option>
                        <option value="cool">Cool</option>
                    </select>
                </label>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                    Coverage
                    <select id="filter-coverage" wire:model.live="coverage" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">All</option>
                        <option value="inside">Inside zone</option>
                        <option value="outside">Outside zone</option>
                    </select>
                </label>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                    Hit recency
                    <select id="filter-recency" wire:model.live="recency" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">All</option>
                        <option value="48h">Under 48 hours</option>
                        <option value="10d">2–10 days</option>
                        <option value="older">Older</option>
                    </select>
                </label>
                <label class="flex items-end gap-2 pb-2 text-sm text-gray-800 dark:text-gray-100">
                    <input id="filter-unassigned" type="checkbox" wire:model.live="unassigned" class="rounded border-gray-300">
                    Unassigned only
                </label>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-filament::button size="sm" color="gray" wire:click="clearFilters">Clear filters</x-filament::button>
                @if ($selected === [])
                    <x-filament::button size="sm" id="bulk-dispatch" disabled>Dispatch selected (0)</x-filament::button>
                @else
                    <x-filament::button size="sm" id="bulk-dispatch" wire:click="openDispatch">
                        Dispatch selected ({{ count($selected) }})
                    </x-filament::button>
                @endif
            </div>
        </div>

        <div id="opportunities-queue" class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2">
                            <input type="checkbox" aria-label="Select all visible" @checked($allVisibleSelected) wire:click="toggleAll">
                        </th>
                        @foreach ([
                            'score' => 'Score',
                            'plate' => 'Plate',
                            'client' => 'Client',
                            'order_age' => 'Order age',
                            'newest_hit' => 'Newest hit',
                            'address' => 'Best address',
                            'distance' => 'Distance',
                            'driver' => 'Driver',
                            'status' => 'Status',
                        ] as $column => $label)
                            <th class="px-3 py-2">
                                <button type="button" class="inline-flex items-center gap-1" wire:click="sortBy('{{ $column }}')">
                                    {{ $label }}
                                    @if ($sort === $column)
                                        <span>{{ $dir === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($board['rows'] as $row)
                        <tr id="opportunity-row-{{ $row['plate'] }}" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5" wire:click="openCase({{ $row['id'] }})">
                            <td class="px-3 py-3" wire:click.stop>
                                <input type="checkbox" value="{{ $row['id'] }}" wire:model.live="selected" aria-label="Select {{ $row['plate'] }}">
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center gap-2">
                                    <span class="inline-flex min-w-10 justify-center rounded-md px-2 py-1 text-sm font-semibold text-white {{ $row['band'] === 'hot' ? 'bg-[#FF6666]' : ($row['band'] === 'warm' ? 'bg-amber-500' : 'bg-slate-400') }}">{{ $row['score'] }}</span>
                                    <span class="text-xs font-medium text-gray-500">{{ $row['band_label'] }}</span>
                                </span>
                            </td>
                            <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                                {{ $row['plate'] }}
                                <span class="block text-xs font-normal text-gray-500">{{ $row['state'] }} · {{ $row['order_id'] }}</span>
                            </td>
                            <td class="px-3 py-3">{{ $row['client'] }}</td>
                            <td class="px-3 py-3">{{ $row['order_age_label'] }}</td>
                            <td class="px-3 py-3">{{ $row['newest_hit_label'] }}</td>
                            <td class="px-3 py-3">
                                {{ $row['best_address'] }}
                                @if ($row['in_coverage'] === true)
                                    <span class="mt-1 block text-xs text-gray-500">Inside zone</span>
                                @elseif ($row['in_coverage'] === false)
                                    <span class="mt-1 block text-xs text-amber-700">Outside zone</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">{{ $row['best_distance_label'] }}</td>
                            <td class="px-3 py-3">{{ $row['driver_name'] ? $row['driver_name'].' · '.$row['kit_name'] : 'Unassigned' }}</td>
                            <td class="px-3 py-3">{{ $row['status_label'] }}</td>
                        </tr>
                        <tr class="bg-white dark:bg-transparent">
                            <td colspan="10" class="px-3 pb-3 pt-0 text-gray-600 dark:text-gray-300">
                                <button type="button" class="text-left" wire:click="openCase({{ $row['id'] }})">{{ $row['reason'] }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-3 py-8 text-center text-gray-500">No open assignments match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @include('filament.pages.partials.opportunity-drawer', ['drawer' => $board['drawer']])
    @include('filament.pages.partials.opportunity-dispatch', ['board' => $board])
</div>

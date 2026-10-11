@props(['items', 'colors' => [], 'showPercentage' => true])

@php
    $numericItems = collect($items)->filter(fn ($item) => is_numeric($item['value']));
    $maxValue = $numericItems->max('value');
    $totalValue = $numericItems->sum('value');

    $defaultColors = [
        'Open' => 'bg-success-500',
        'Repossessed' => 'bg-primary-500',
        'Closed' => 'bg-danger-500',
        'Hold' => 'bg-purple-500',
    ];
    $colors = array_merge($defaultColors, $colors);
@endphp

<div class="space-y-3">
    @foreach ($items as $item)
        @php
            $isNumeric = is_numeric($item['value']);
            $percentage = $isNumeric && $totalValue > 0 ? round(($item['value'] / $totalValue) * 100, 1) : 0;
            $barWidth = $isNumeric && $maxValue > 0 ? round(($item['value'] / $maxValue) * 100, 1) : 0;
            $colorClass = $colors[$item['label']] ?? 'bg-gray-500';
            $displayValue = $isNumeric ? number_format($item['value']) : $item['value'];
        @endphp
        <div x-data="{ showTooltip: false }" class="relative">
            <div class="flex items-center justify-between text-sm mb-1">
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $item['label'] }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ $displayValue }}</span>
            </div>
            <div
                class="relative h-3 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden cursor-pointer"
                x-on:mouseenter="showTooltip = true"
                x-on:mouseleave="showTooltip = false"
            >
                <div
                    class="{{ $colorClass }} h-full rounded-full transition-all duration-500"
                    style="width: {{ $barWidth }}%"
                ></div>
            </div>

            <div
                x-show="showTooltip"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-1"
                class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 rounded-lg bg-gray-900 dark:bg-gray-600 px-3 py-2 text-sm text-white shadow-lg z-50 whitespace-nowrap pointer-events-none"
                x-cloak
            >
                <div class="font-semibold">{{ $item['label'] }}: {{ $displayValue }}</div>
                @if ($showPercentage && $isNumeric && $totalValue > 0)
                    <div class="text-gray-300">{{ $percentage }}% of total ({{ number_format($totalValue) }})</div>
                @endif
            </div>
        </div>
    @endforeach
</div>

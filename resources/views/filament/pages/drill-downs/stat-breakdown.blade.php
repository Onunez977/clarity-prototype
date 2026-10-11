@php
    $toggleable = ! empty($barChartToggleable);
    $defaultStatuses = $barChartDefaultStatuses ?? [];
    $statusColumnMap = $clientTableStatusColumnMap ?? [];
@endphp

@if ($toggleable)
<div
    x-data="{
        activeStatuses: @js($defaultStatuses),
        statusColumnMap: @js($statusColumnMap),
        toggle(label) {
            if (this.isActive(label) && this.activeStatuses.length <= 1) return;
            if (this.isActive(label)) {
                this.activeStatuses = this.activeStatuses.filter(s => s !== label);
            } else {
                this.activeStatuses.push(label);
            }
        },
        isActive(label) {
            return this.activeStatuses.includes(label);
        }
    }"
    class="space-y-6"
>
    @include('filament.pages.drill-downs.components.date-range', ['dates' => $dates])

    @if (! empty($summaryValue))
        <div class="flex items-baseline gap-2">
            <span class="text-2xl font-bold text-gray-900 dark:text-white">{{ $summaryValue }}</span>
            @if (! empty($summaryLabel))
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $summaryLabel }}</span>
            @endif
        </div>
    @endif

    @if (! empty($barChart))
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ $barChartTitle ?? 'Breakdown' }}</h3>
            @include('filament.pages.drill-downs.components.stacked-bar', [
                'items' => $barChart,
                'colors' => $barChartColors ?? [],
                'toggleable' => true,
            ])
        </div>
    @endif

    @if (! empty($monthlyTrend))
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ $monthlyTrendTitle ?? 'Monthly Trend' }}</h3>
            @include('filament.pages.drill-downs.components.monthly-trend', [
                'data' => $monthlyTrend,
                'color' => $monthlyTrendColor ?? 'bg-primary-500',
            ])
        </div>
    @endif

    @if (! empty($clientTable))
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ $clientTableTitle ?? 'Per Client' }}</h3>
            @include('filament.pages.drill-downs.components.client-table', [
                'rows' => $clientTable,
                'columns' => $clientTableColumns,
                'valueKey' => $clientTableValueKey ?? 'value',
                'toggleable' => true,
                'statusColumnMap' => $statusColumnMap,
            ])
        </div>
    @endif
</div>
@else
<div class="space-y-6">
    @include('filament.pages.drill-downs.components.date-range', ['dates' => $dates])

    @if (! empty($summaryValue))
        <div class="flex items-baseline gap-2">
            <span class="text-2xl font-bold text-gray-900 dark:text-white">{{ $summaryValue }}</span>
            @if (! empty($summaryLabel))
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $summaryLabel }}</span>
            @endif
        </div>
    @endif

    @if (! empty($barChart))
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ $barChartTitle ?? 'Breakdown' }}</h3>
            @if (($barChartType ?? 'bar') === 'stacked')
                @include('filament.pages.drill-downs.components.stacked-bar', [
                    'items' => $barChart,
                    'colors' => $barChartColors ?? [],
                ])
            @else
                @include('filament.pages.drill-downs.components.bar-chart', [
                    'items' => $barChart,
                    'colors' => $barChartColors ?? [],
                    'showPercentage' => $barChartShowPercentage ?? true,
                ])
            @endif
        </div>
    @endif

    @if (! empty($monthlyTrend))
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ $monthlyTrendTitle ?? 'Monthly Trend' }}</h3>
            @include('filament.pages.drill-downs.components.monthly-trend', [
                'data' => $monthlyTrend,
                'color' => $monthlyTrendColor ?? 'bg-primary-500',
            ])
        </div>
    @endif

    @if (! empty($clientTable))
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ $clientTableTitle ?? 'Per Client' }}</h3>
            @include('filament.pages.drill-downs.components.client-table', [
                'rows' => $clientTable,
                'columns' => $clientTableColumns,
                'valueKey' => $clientTableValueKey ?? 'value',
            ])
        </div>
    @endif
</div>
@endif

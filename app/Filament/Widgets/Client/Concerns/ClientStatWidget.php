<?php

namespace App\Filament\Widgets\Client\Concerns;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;

abstract class ClientStatWidget extends StatsOverviewWidget
{
    use ClientStatHelper;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    protected int|array|null $columns = 1;

    public ?int $org_id = null;

    public ?int $branch_id = null;

    public ?int $client_org_id = null;

    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?string $date_type = 'this_month';

    abstract protected function getLabel(): string;

    protected function getDrillDownKey(): ?string
    {
        return null;
    }

    protected function getQueryClass(): ?string
    {
        return null;
    }

    protected function getBatchCacheKey(): ?string
    {
        return null;
    }

    protected function buildBatchCacheKeyForDates(string $start, string $end): string
    {
        $orgId = $this->org_id ?? auth()->user()->org_id;
        $timezone = $this->getTimezone();
        $branchId = $this->branch_id ?? '';

        return implode(':', [
            'client-ranked-metrics-batch',
            'org_id='.$orgId,
            'branch_id='.$branchId,
            'start='.str_replace(' ', '_', $start),
            'end='.str_replace(' ', '_', $end),
            'timezone='.$timezone,
        ]);
    }

    protected function getValueFromBatchCache(): mixed
    {
        $batchKey = $this->getBatchCacheKey();
        if ($batchKey === null || $this->client_org_id === null) {
            return null;
        }

        $batchCacheKey = $this->buildBatchCacheKeyForDates($this->getStartDate(), $this->getEndDate());
        $batchData = cache()->get($batchCacheKey);

        if ($batchData === null || ! is_array($batchData)) {
            return null;
        }

        return $batchData[$this->client_org_id][$batchKey] ?? null;
    }

    protected function getPreviousValueFromBatchCache(): mixed
    {
        $batchKey = $this->getBatchCacheKey();
        if ($batchKey === null || $this->client_org_id === null) {
            return null;
        }

        $previousDates = $this->getPreviousPeriodDates($this->date_type ?? 'this_month', $this->getTimezone());
        if ($previousDates === null) {
            return null;
        }

        $batchCacheKey = $this->buildBatchCacheKeyForDates($previousDates['start'], $previousDates['end']);
        $batchData = cache()->get($batchCacheKey);

        if ($batchData === null || ! is_array($batchData)) {
            return null;
        }

        return $batchData[$this->client_org_id][$batchKey] ?? null;
    }

    protected function getModelQuery(): mixed
    {
        return $this->getModelQueryForDates($this->getStartDate(), $this->getEndDate());
    }

    public function getModelQueryForDates(string $startDate, string $endDate): mixed
    {
        return null;
    }

    protected function getStartDate(): string
    {
        if (\in_array($this->date_type, ['this_month', 'ytd'], true)) {
            $dateRange = $this->getDateRangeForType($this->date_type);

            return $dateRange['start']->toDateTimeString();
        } elseif (! empty($this->start_date)) {
            return Carbon::parse($this->start_date, $this->getTimezone())->toDateTimeString();
        } else {
            $dateRange = $this->getDateRangeForType($this->date_type ?? 'this_month');

            return $dateRange['start']->toDateTimeString();
        }
    }

    protected function getEndDate(): string
    {
        if (\in_array($this->date_type, ['this_month', 'ytd'], true)) {
            $dateRange = $this->getDateRangeForType($this->date_type);

            return $dateRange['end']->toDateTimeString();
        } elseif (! empty($this->end_date)) {
            return Carbon::parse($this->end_date, $this->getTimezone())->toDateTimeString();
        } else {
            $dateRange = $this->getDateRangeForType($this->date_type ?? 'this_month');

            return $dateRange['end']->toDateTimeString();
        }
    }

    protected function getTimezone(): string
    {
        return auth()->user()?->timezone ?? 'UTC';
    }

    protected function getDescription(): ?string
    {
        return null;
    }

    protected function getTooltipDescription(): ?string
    {
        return null;
    }

    protected function getStatClass(): ?string
    {
        return null;
    }

    protected function getPreviousPeriodValue(): mixed
    {
        $previousDates = $this->getPreviousPeriodDates($this->date_type ?? 'this_month', $this->getTimezone());

        if ($previousDates === null) {
            return null;
        }

        $batchValue = $this->getPreviousValueFromBatchCache();
        if ($batchValue !== null) {
            return $batchValue;
        }

        $orgId = $this->org_id ?? auth()->user()->org_id;

        $cacheKey = $this->buildCacheKey(
            dateType: 'previous_'.($this->date_type ?? 'this_month'),
            orgId: $orgId,
            startDate: $previousDates['start'],
            endDate: $previousDates['end'],
            branchId: $this->branch_id,
            clientOrgId: $this->client_org_id,
        );

        return cache()->remember(
            $cacheKey,
            $this->getCacheTTL(),
            fn () => $this->getModelQueryForDates($previousDates['start'], $previousDates['end'])
        );
    }

    protected function calculatePercentageChange(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return (($current - $previous) / $previous) * 100;
    }

    protected function getComparisonLabel(): ?string
    {
        return match ($this->date_type) {
            'this_month' => 'last month',
            'ytd' => 'last year',
            'last_30d' => 'prev. 30d',
            'last_90d' => 'prev. 90d',
            default => null,
        };
    }

    protected function formatComparisonDescription(mixed $current, mixed $previous): ?string
    {
        if ($previous === null) {
            return null;
        }

        $change = $this->calculatePercentageChange((float) $current, (float) $previous);
        $formattedPrevious = $this->formatPreviousValue($previous);

        $label = $this->getComparisonLabel();
        if ($label === null) {
            return sprintf('%.1f%% (was %s)', abs($change), $formattedPrevious);
        }

        return sprintf('%.1f%% (%s %s)', abs($change), $formattedPrevious, $label);
    }

    protected function getComparisonColor(float $change): string
    {
        $lowerIsBetter = $this->isLowerBetter();

        if ($change > 0) {
            return $lowerIsBetter ? 'danger' : 'success';
        }

        if ($change < 0) {
            return $lowerIsBetter ? 'success' : 'danger';
        }

        return 'gray';
    }

    protected function formatValue(mixed $rawValue): string
    {
        if (is_numeric($rawValue)) {
            $floatValue = (float) $rawValue;

            return floor($floatValue) == $floatValue
                ? number_format((int) $floatValue)
                : number_format($floatValue, 1);
        }

        return (string) $rawValue;
    }

    protected function formatPreviousValue(mixed $previousValue): string
    {
        return $this->formatValue($previousValue);
    }

    protected function isLowerBetter(): bool
    {
        return false;
    }

    protected function getComparisonIcon(float $change): ?string
    {
        if ($change > 0) {
            return 'heroicon-m-arrow-trending-up';
        }

        if ($change < 0) {
            return 'heroicon-m-arrow-trending-down';
        }

        return null;
    }

    public function placeholder(): View
    {
        return view('filament.widgets.placeholder', [
            'label' => $this->getLabel(),
        ]);
    }

    protected function getStats(): array
    {
        $label = $this->getLabel();
        $orgId = $this->org_id ?? auth()->user()->org_id;

        $rawValue = $this->getValueFromBatchCache();

        if ($rawValue === null && ($queryClass = $this->getQueryClass()) !== null) {
            $rawValue = $queryClass::query([
                'org_id' => $orgId,
                'branch_id' => $this->branch_id ?? null,
                'start' => $this->getStartDate(),
                'end' => $this->getEndDate(),
                'client_org_id' => $this->client_org_id ?? null,
                'timezone' => $this->getTimezone(),
            ], cache: true);
        }

        if ($rawValue === null) {
            $rawValue = cache()->remember(
                $this->buildCacheKey(
                    dateType: $this->date_type ?? 'this_month',
                    orgId: $orgId,
                    startDate: $this->start_date,
                    endDate: $this->end_date,
                    branchId: $this->branch_id,
                    clientOrgId: $this->client_org_id,
                ),
                $this->getCacheTTL(),
                fn () => $this->getModelQuery()
            );
        }

        $previousValue = $this->getPreviousPeriodValue();
        $value = $this->formatValue($rawValue);

        $extraAttributes = [
            'data-stat' => $label.': '.$value,
            'data-description' => $this->getTooltipDescription(),
        ];

        if ($drillDownKey = $this->getDrillDownKey()) {
            $extraAttributes['class'] = trim(($this->getStatClass() ?? '').' cursor-pointer hover:ring-2 hover:ring-primary-500/50 hover:shadow-md transition-all duration-150');
            $extraAttributes['wire:click'] = "\$dispatch('openDrillDown', { stat: '{$drillDownKey}' })";
            $extraAttributes['title'] = 'Click for detailed breakdown';
        } elseif ($class = $this->getStatClass()) {
            $extraAttributes['class'] = $class;
        }

        $stat = Stat::make($label, $value)
            ->extraAttributes($extraAttributes);

        if ($previousValue !== null) {
            $change = $this->calculatePercentageChange((float) $rawValue, (float) $previousValue);
            $description = $this->formatComparisonDescription($rawValue, $previousValue);
            $icon = $this->getComparisonIcon($change);
            $color = $this->getComparisonColor($change);

            $stat->description($description)
                ->color($color);

            if ($icon) {
                $stat->descriptionIcon($icon);
            }
        }

        return [$stat];
    }

    #[On('refresh-dashboard')]
    public function refreshData(int|string|null $branch_id = null, ?string $start_date = null, ?string $end_date = null, ?string $date_type = null, int|string|null $client_org_id = null): void
    {
        $this->branch_id = ($branch_id !== null && $branch_id !== '' && is_numeric($branch_id))
            ? (int) $branch_id
            : null;

        $this->client_org_id = ($client_org_id !== null && $client_org_id !== '' && is_numeric($client_org_id))
            ? (int) $client_org_id
            : null;

        $this->start_date = $start_date;
        $this->end_date = $end_date;
        $this->date_type = $date_type ?? 'this_month';
    }
}

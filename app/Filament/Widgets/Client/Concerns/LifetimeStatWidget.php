<?php

namespace App\Filament\Widgets\Client\Concerns;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;

abstract class LifetimeStatWidget extends StatsOverviewWidget
{
    use ClientStatHelper;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public ?int $org_id = null;

    public ?int $branch_id = null;

    public ?int $client_org_id = null;

    abstract protected function getLabel(): string;

    protected function getQueryClass(): ?string
    {
        return null;
    }

    protected function getModelQuery(): mixed
    {
        return null;
    }

    protected function getDescription(): ?string
    {
        return null;
    }

    protected function getTooltipDescription(): ?string
    {
        return null;
    }

    protected function getStartDate(): string
    {
        return '2020-01-01 00:00:00';
    }

    protected function getEndDate(): string
    {
        return now()->endOfDay()->toDateTimeString();
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

        $startDate = '2020-01-01 00:00:00';
        $endDate = now()->endOfDay()->toDateTimeString();

        if (($queryClass = $this->getQueryClass()) !== null) {
            $value = $queryClass::query([
                'org_id' => $orgId,
                'branch_id' => $this->branch_id ?? null,
                'start' => $startDate,
                'end' => $endDate,
                'client_org_id' => $this->client_org_id ?? null,
                'timezone' => auth()->user()?->timezone ?? null,
                'cacheTTL' => $this->getCacheTTL(30),
            ], cache: true);
        } else {
            $value = cache()->remember(
                $this->buildCacheKey(
                    dateType: 'lifetime',
                    orgId: $orgId,
                    branchId: $this->branch_id,
                    clientOrgId: $this->client_org_id,
                ),
                $this->getCacheTTL(30),
                fn () => $this->getModelQuery()
            );
        }

        if (is_numeric($value)) {
            $floatValue = (float) $value;
            if (floor($floatValue) == $floatValue) {
                $value = number_format((int) $floatValue);
            } else {
                $value = number_format($floatValue, 1);
            }
        }

        $stat = Stat::make($label, $value)
            ->extraAttributes([
                'data-stat' => $label.': '.$value,
                'data-description' => $this->getTooltipDescription(),
            ]);

        return [$stat];
    }

    #[On('refresh-dashboard')]
    public function refreshData(int|string|null $branch_id = null, ?string $start_date = null, ?string $end_date = null, ?string $date_type = null, int|string|null $client_org_id = null): void
    {
        // Only update branch and client filters, ignore date filters for lifetime stats
        $this->branch_id = ($branch_id !== null && $branch_id !== '' && is_numeric($branch_id))
            ? (int) $branch_id
            : null;

        $this->client_org_id = ($client_org_id !== null && $client_org_id !== '' && is_numeric($client_org_id))
            ? (int) $client_org_id
            : null;
    }
}

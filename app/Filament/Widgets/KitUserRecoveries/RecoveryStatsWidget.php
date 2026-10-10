<?php

namespace App\Filament\Widgets\KitUserRecoveries;

use App\Clickhouse\Models\Recovery as ClickhouseRecovery;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Attributes\On;

class RecoveryStatsWidget extends BaseWidget
{
    public ?array $pageFilters = null;

    public string $leader = 'N/A';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    private bool $failSoft = true;

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        $startDate = $this->pageFilters['start_date'] ?? now()->startOfMonth()->startOfDay();
        $endDate = $this->pageFilters['end_date'] ?? now();

        try {
            $total = ClickhouseRecovery::query()
                ->forOrg(auth()->user()->org_id)
                ->between($startDate, $endDate)
                ->count();
        } catch (\Throwable $e) {
            if (! $this->failSoft) {
                throw $e;
            }

            report($e);
            $total = 0;
        }

        return [
            Stat::make('Leader', $this->leader)
                ->icon('heroicon-o-rocket-launch')
                ->extraAttributes([
                    'data-stat' => 'Leader: '.$this->leader,
                ]),

            Stat::make('Total Recoveries', \number_format($total))
                ->icon('heroicon-o-wallet')
                ->extraAttributes([
                    'data-stat' => 'Total Recoveries: '.$total,
                ]),

            //            Stat::make('Total Repossessions', \number_format($this->repossessions))
            //                ->icon('heroicon-o-tag')
            //                ->extraAttributes([
            //                    'data-stat' => 'Total Repossessions: '.$this->repossessions,
            //                ]),
        ];
    }

    /**
     * Rethrow a ClickHouse failure instead of rendering 0, for callers that must not
     * mistake an outage for a real count (Otto, DEV-2706).
     */
    public function failLoudly(): static
    {
        $this->failSoft = false;

        return $this;
    }

    #[On('reload-recoveries-total')]
    public function reload(array $filters): void
    {
        $this->pageFilters = $filters;
    }

    #[On('update-recovery-leader')]
    public function updateLeader(?string $kitName = null): void
    {
        $this->leader = $kitName ?? 'N/A';
    }

    public static function canView(): bool
    {
        return auth()->user()->can(PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);
    }
}

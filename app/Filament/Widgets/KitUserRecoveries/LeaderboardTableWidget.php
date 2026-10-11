<?php

namespace App\Filament\Widgets\KitUserRecoveries;

use App\Clickhouse\Models\RecoveryKitLeaderboard;
use App\Facades\Analytics;
use App\Filament\Exports\RecoveryLeaderboardExporter;
use App\Filament\Tables\Filters\KitUsersFilter;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Attributes\On;

class LeaderboardTableWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public ?array $pageFilters = null;

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function table(Table $table): Table
    {
        $startDate = $this->pageFilters['start_date'] ?? now()->startOfMonth()->startOfDay();
        $endDate = $this->pageFilters['end_date'] ?? now();
        $kits = KitUsersFilter::format($this->pageFilters['kits'] ?? []);

        $query = RecoveryKitLeaderboard::query()
            ->final()
            ->forOrg(auth()->user()->org_id)
            ->between(convertToUtc($startDate), convertToUtc($endDate))
            ->forKits($kits)
            ->selectRaw('kit_id, kit_id as id, kit_name as name, round(sum(hotlist_scan_percentage) / 100, 2) as total, row_number() OVER (ORDER BY total DESC) as rank')
            ->groupBy('kit_id', 'kit_name')
            ->havingRaw('total > 0')
            ->orderByDesc('total');

        $leader = (clone $query)->first();
        $this->dispatch('update-recovery-leader', kitName: $leader?->name);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Kit Name')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->headerTooltip('Sum of scan percentages across all recoveries. Each recovery contributes (kit scans / total scans) as a percentage.')
                    ->default(0)
                    ->sortable(),

                Tables\Columns\TextColumn::make('rank')
                    ->label('Rank')
                    ->default(0)
                    ->sortable(),
            ])
            ->headerActions([
                ExportAction::make('download')
                    ->hiddenLabel()
                    ->hidden(fn (): bool => $this->getTable()->getRecords()->count() === 0)
                    ->icon('heroicon-m-arrow-down-tray')
                    ->exporter(RecoveryLeaderboardExporter::class)
                    ->tooltip('Export as CSV')
                    ->fileName(fn (Export $export): string => 'recovery-leaderboard-'.$export->getKey())
                    ->chunkSize(250)
                    ->columnMapping(false)
                    ->modal(false)
                    ->after(fn () => Analytics::track('export', auth()->user(), ['action' => 'export'], 'kit-user-recoveries-leaderboard')),
            ])
            ->query($query)
            ->paginationPageOptions([5, 10, 25, 50, 100, 'all'])
            ->defaultSort('rank', 'asc')
            ->defaultKeySort(false)
            ->emptyStateHeading('No Leaderboard Entries');
    }

    public function getTableRecordKey(Model|array $record): string
    {
        return $record?->id ?? uniqid();
    }

    #[On('reload-recoveries-leaderboard')]
    public function reload(array $filters): void
    {
        $this->pageFilters = $filters;

        $this->resetTable();
    }

    public static function canView(): bool
    {
        return auth()->user()->can(PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);
    }
}

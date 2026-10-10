<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Models\Kit as ClickhouseKit;
use App\Clickhouse\Models\Scan as ClickhouseScan;
use App\Filament\Widgets\Dashboard\Concerns\DashboardMetricCard;
use App\Filament\Widgets\Dashboard\Concerns\RendersDashboardStat;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class KitUsers extends BaseWidget implements DashboardMetricCard
{
    use RendersDashboardStat;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    public bool $expanded = false;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 1,
    ];

    protected function getColumns(): int
    {
        return 1;
    }

    protected static ?string $heading = 'Active Kit Users';

    public function boot(): void
    {
        abort_unless(static::canView(), 403);
    }

    /**
     * The compact-card identifier for this widget.
     *
     * Resolved by nothing today: DEV-2460 removed 'kit-users' from
     * CompanyMetricsOverview::cards(). Retained because KitUsersTest drives this class
     * directly and the expand-to-table view may be rehomed later. On which array is the
     * key registry, see DashboardWidget::getCardKey().
     */
    public function getCardKey(): string
    {
        return 'kit-users';
    }

    /**
     * Point-in-time counts, no time series — Stat has a description instead of a chart.
     *
     * SUPERSEDED by HotlistCount's 'Active Kit Users' header stat (DEV-2460), so nothing
     * renders this Stat today. The description below is the OLD secondary figure
     * ('inactive kits'); the shipped header stat shows the org's total kit count instead,
     * labelled "kits" and deliberately not "camera kits" (cameraKitsCount() applies no
     * kit_type predicate). If you rehome this widget, take the header stat as current and
     * do not reintroduce a camera claim over an unfiltered count.
     */
    public function toStat(?string $selectedCard = null): Stat
    {
        return $this->dashboardStat(
            Stat::make('Active Kit Users', \number_format($this->activeKitUsersCount()))
                ->description(\number_format($this->inactiveKitsCount()).' inactive kits')
                ->color('gray'),
            $selectedCard,
        );
    }

    /**
     * Collapsed card's first number: total org kit count (point-in-time, not date-filtered).
     */
    public function cameraKitsCount(): int
    {
        $orgId = auth()->user()->org_id;

        // Deliberately a plain remember() with no catch, matching what the removed
        // KitStatusGraph did for this same count. Wrapping the query in a catch INSIDE
        // remember() cached a fabricated 0 until midnight, which is worse than letting a
        // ClickHouse failure surface.
        return cache()->remember(
            'kit-status:total:org='.$orgId,
            now()->endOfDay(),
            fn () => (int) ClickhouseKit::query()->forOrg($orgId)->count()
        );
    }

    /**
     * Collapsed card's description: kits with NO scan in the active window, i.e. the org's
     * kits less the active ones.
     *
     * Clamped at zero deliberately. activeKitUsersCount() counts kit_ids seen SCANNING, and
     * a scan can reference a kit with no corresponding kit row (a deleted kit, or CDC lag) --
     * see the note on that method -- so the active count can legitimately exceed
     * cameraKitsCount(). Subtracting unclamped would render a nonsense "-2 inactive kits" on
     * the dashboard.
     */
    public function inactiveKitsCount(): int
    {
        return \max(0, $this->cameraKitsCount() - $this->activeKitUsersCount());
    }

    /**
     * Collapsed card's second number: the ACTIVE kit-user count, i.e. the same
     * cachedActiveKitIds() population the expanded panel filters on. Definition deliberately
     * unchanged from what the dashboard already used (DEV-2324 Q5).
     *
     * Note this counts kit_ids seen scanning, so it can exceed the panel's row count if a
     * scan references a kit with no corresponding kit row (a deleted kit, or CDC lag). It is
     * the pre-existing definition, not a new one — do not "fix" it here.
     */
    public function activeKitUsersCount(): int
    {
        return count($this->cachedActiveKitIds());
    }

    private function cachedActiveKitIds(): array
    {
        $orgId = auth()->user()->org_id;
        $tz = auth()->user()->timezone ?? 'UTC';

        return cache()->remember(
            'kit-status:active-ids:org='.$orgId,
            now()->endOfDay(),
            fn () => ClickhouseScan::query()
                ->forOrg($orgId)
                ->between(
                    start: now()->setTimezone($tz)->subDays(3)->startOfDay(),
                    end: now()->setTimezone($tz)->endOfDay(),
                    timezone: $tz,
                )
                ->selectRaw('DISTINCT kit_id')
                ->pluck('kit_id')
                ->toArray()
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Kit Name'),
            ])
            ->query(
                ClickhouseKit::query()
                    ->forOrg(auth()->user()->org_id)
                    ->select(['id', 'name'])
                    ->whereIn('id', $this->cachedActiveKitIds())
            )
            ->defaultSort('name')
            ->paginationPageOptions([10, 'all'])
            ->emptyStateHeading('No Kit Users');
    }

    public function getTableRecordKey(Model|array $record): string
    {
        return $record?->id ?? uniqid();
    }

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(RoleEnum::ADMIN->value, RoleEnum::USER->value);
    }
}

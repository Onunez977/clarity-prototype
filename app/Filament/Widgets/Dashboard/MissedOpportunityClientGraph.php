<?php

namespace App\Filament\Widgets\Dashboard;

use App\Clickhouse\Models as Clickhouse;
use App\Enums\FeatureEnum;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Laravel\Pennant\Feature;

class MissedOpportunityClientGraph extends ChartWidget
{
    protected ?string $heading = 'Missed Opportunities by Client (YTD)';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = ['default' => 'full'];

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getMaxHeight(): ?string
    {
        $barCount = count($this->clients()['labels'] ?? []);

        return max(300, $barCount * 55).'px';
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected ?array $options = [
        'indexAxis' => 'y',
        'layout' => [
            'padding' => ['left' => 10, 'right' => 20, 'top' => 10, 'bottom' => 10],
        ],
        'scales' => [
            'x' => ['beginAtZero' => true, 'grid' => ['display' => true]],
            'y' => [
                'grid' => ['display' => false],
                'ticks' => ['autoSkip' => false, 'padding' => 8, 'font' => ['size' => 11]],
            ],
        ],
        'responsive' => true,
        'maintainAspectRatio' => false,
        'plugins' => [
            'legend' => ['display' => false],
        ],
    ];

    public function getHeading(): string|Htmlable|null
    {
        return $this->clients() === null
            ? 'No Data for Client Graph'
            : $this->heading;
    }

    public function clients(): ?array
    {
        $orgId = auth()->user()->org_id;
        $timezone = auth()->user()->timezone ?? 'UTC';

        return cache()->remember(
            'missed-opportunity:client-graph:org='.$orgId.':tz='.$timezone.':v2',
            now()->addMinutes(15),
            function () use ($orgId, $timezone) {
                $start = now(tz: $timezone)->utc()->startOfYear();
                $end = now(tz: $timezone)->utc()->endOfMonth()->endOfDay();

                $results = Clickhouse\MissedOpportunity::query()
                    ->forOrg($orgId)
                    ->notNicb()
                    ->between($start, $end, $timezone)
                    ->groupBy('client_org_name')
                    ->selectRaw('client_org_name, countDistinct(hotlist_id) as total')
                    ->pluck('total', 'client_org_name')
                    ->sortKeys();

                if ($results->isEmpty()) {
                    return null;
                }

                $sorted = $results->sortDesc();

                $palette = [
                    '#EF4444', // red
                    '#3B82F6', // blue
                    '#F59E0B', // amber
                    '#10B981', // emerald
                    '#EC4899', // pink
                    '#8B5CF6', // violet
                    '#F97316', // orange
                    '#06B6D4', // cyan
                    '#84CC16', // lime
                    '#A855F7', // purple
                    '#14B8A6', // teal
                    '#E11D48', // rose
                    '#CA8A04', // yellow-dark
                    '#0891B2', // cyan-dark
                    '#7C3AED', // indigo
                    '#64748B', // slate
                ];

                $top = $sorted->take(10);
                $remaining = $sorted->skip(10);

                if ($remaining->isNotEmpty()) {
                    $otherLabel = 'Other ('.$remaining->count().' clients)';
                    $top = $top->put($otherLabel, $remaining->sum());
                }

                $colors = $top->values()->map(fn ($v, $i) => $palette[$i % \count($palette)])->toArray();

                return [
                    'data' => $top->values()->toArray(),
                    'labels' => $top->keys()->toArray(),
                    'colors' => $colors,
                ];
            });
    }

    protected function getData(): array
    {
        if ($this->clients() === null) {
            return [];
        }

        return [
            'datasets' => [
                [
                    'data' => $this->clients()['data'] ?? [],
                    'backgroundColor' => $this->clients()['colors'] ?? [],
                    'borderWidth' => 0,
                    'borderRadius' => 4,
                    'barThickness' => 32,
                ],
            ],
            'labels' => $this->clients()['labels'] ?? [],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public static function canView(): bool
    {
        return Feature::active(FeatureEnum::MISSED_OPPORTUNITY->slug()) || session()->has('admin_impersonator');
    }
}

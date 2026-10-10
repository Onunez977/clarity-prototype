<?php

namespace App\Filament\Widgets\Hits;

use App\Filament\Concerns\HasHitMetricCards;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Reactive;

/**
 * Header widget for the Hits list page (DEV-2546). Deliberately queryless: the page
 * computes the counts from its own filtered table query
 * ({@see HasHitMetricCards}) and passes them down through
 * ListHits::getWidgetData(), so there is exactly one query and no second page instance
 * that could drift from the table's filter state.
 *
 * `#[Reactive]` is what makes the cards follow the filters: Filament re-evaluates the
 * widget data closure on every parent render, whichever path changed the filters.
 *
 * The array is always exactly the four cards of AC-1 (operator decision, RN5, 2026-09-15).
 * Hit types M, F and P can still reach the table and are NOT counted by any of the four,
 * so a table populated only with those types leaves all four cards reading 0 — accepted,
 * not a bug; see {@see HasHitMetricCards} for why.
 */
class HitMetricCards extends StatsOverviewWidget
{
    /**
     * @var array<int, array{key: string, label: string, color: string, unique: int|null, total: int|null}>
     */
    #[Reactive]
    public array $hitMetricCards = [];

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return \array_map(
            fn (array $card): Stat => Stat::make($card['label'], $this->formatCardValue($card['unique']))
                ->description($card['total'] === null
                    ? 'Count unavailable'
                    : \number_format($card['total']).' total hits')
                ->color($card['color'])
                ->extraAttributes([
                    'data-hit-metric-card' => $card['key'],
                    'title' => 'Hits counted once per plate, with the raw total below',
                ]),
            $this->hitMetricCards,
        );
    }

    /**
     * @return int|array<string, int|null>|null
     */
    protected function getColumns(): int|array|null
    {
        return [
            'default' => 1,
            'md' => 2,
            'lg' => 4,
        ];
    }

    private function formatCardValue(?int $value): string
    {
        return $value === null ? '—' : \number_format($value);
    }
}

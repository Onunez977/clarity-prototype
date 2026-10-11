<?php

namespace App\Filament\Pages;

use App\Facades\Analytics;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Widgets;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use App\Support\Filament\FilamentRouteRedirect;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Attributes\On;

class Dashboard extends BaseDashboard
{
    use FilamentRouteRedirect;
    use HasFiltersForm;

    protected static ?string $navigationLabel = 'Company Metrics';

    public const EXPANDED_DETAIL_ELEMENT_ID = 'dashboard-expanded-detail';

    /**
     * The single card key currently expanded, or null when the shared detail region
     * (rendered below the whole card grid) is closed. A scalar property -- rather than
     * a per-card boolean -- is what makes "only one open at a time, opening a new one
     * replaces the previous" structural instead of logic-enforced (DEV-2324 Q8).
     */
    public ?string $expandedCard = null;

    public function mount(): void
    {
        if (! self::canAccess()) {
            if ($firstAvailableRoute = $this->getFirstAvailableRoute()) {
                $this->redirectIntended($firstAvailableRoute);
            } else {
                abort(403);
            }
        }

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'dashboard');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Company Metrics';
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'lg' => 4,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return $this->getColumns();
    }

    public function getHeaderWidgets(): array
    {
        return [
            Widgets\Dashboard\HotlistCount::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            Widgets\Dashboard\CompanyMetricsOverview::class,
        ];
    }

    /**
     * The cards a toggle key may resolve to: CompanyMetricsOverview::expandableCards(),
     * NOT ::cards(). The two differ when a card is Pro-gated -- such a card keeps its grid
     * slot but is absent here, so it cannot be expanded (DEV-2460).
     *
     * The grid's own layout and ordering live on CompanyMetricsOverview::cards(), whose
     * docblock is the single source of truth for the funnel-family arrangement and the three
     * adjacency constraints it preserves. Do not restate the order here -- one of the two
     * copies would go stale, which is exactly what happened to this docblock before DEV-2460.
     *
     * Keys match getCardKey() (class_basename snake-cased).
     *
     * @return array<string, class-string>
     */
    protected function cardWidgets(): array
    {
        return Widgets\Dashboard\CompanyMetricsOverview::expandableCards();
    }

    /**
     * Toggling the currently-open card closes it; toggling any other card replaces
     * whichever was open. One scalar property can only ever name one card, so
     * "only one open, opening a new one replaces the previous" is structural.
     *
     * Wired from each Stat's extraAttributes `wire:click` `$parent.toggleCard`
     * (the overview widget is nested in this page, so clicks do not re-query the grid).
     *
     * DO NOT add a `cardWidgets()` membership check here. It is not needed -- an unknown or
     * locked key already resolves to nothing in expandedCardComponents() below (`?? null`
     * -> `[]`), so it is a structural no-op, and lockedStat() emits no wire:click in the
     * first place. It is also actively harmful: an early `return` on an unknown key fires
     * BEFORE the close branch, so a card that was open when it became locked could never be
     * closed again. Guarding here is the bug, not the fix (DEV-2460).
     */
    #[On('dashboard-toggle-card')]
    public function toggleCard(string $key): void
    {
        $this->expandedCard = ($this->expandedCard === $key) ? null : $key;

        if ($this->expandedCard !== null) {
            $this->scrollExpandedDetailIntoView();
        }
    }

    /**
     * After Livewire morphs the shared detail region in, scroll it to just below the
     * sticky Filament topbar so the opened chart/table is fully in view.
     */
    protected function scrollExpandedDetailIntoView(): void
    {
        $id = self::EXPANDED_DETAIL_ELEMENT_ID;

        $this->js(<<<JS
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    const el = document.getElementById('{$id}');
                    if (! el) {
                        return;
                    }

                    const header = document.querySelector('.fi-topbar-ctn');
                    const offset = (header ? header.getBoundingClientRect().height : 0) + 16;
                    const top = window.scrollY + el.getBoundingClientRect().top - offset;

                    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
                });
            });
        JS);
    }

    /**
     * The single shared detail region's widget, or [] when nothing is expanded. Reuses
     * Filament's own widget wiring (getWidgetsSchemaComponents() merges getWidgetData()/
     * pageFilters) and keys the child by widget class, so switching which card is open
     * remounts a different component rather than reusing stale state.
     *
     * @return array<Livewire>
     */
    protected function expandedCardComponents(): array
    {
        if ($this->expandedCard === null) {
            return [];
        }

        $class = $this->cardWidgets()[$this->expandedCard] ?? null;

        if ($class === null) {
            return [];
        }

        return collect($this->getWidgetsSchemaComponents([$class], [
            'expanded' => true,
            'date' => $this->resolvePageDateToggle(),
        ]))
            ->map(fn (Livewire $component): Livewire => $component->key($class.'-expanded'))
            ->all();
    }

    /**
     * Native Filament StatsOverview (Stat::chart sparklines) plus ONE shared detail
     * region below the grid. Clicking a Stat calls `$parent.toggleCard`; the
     * expanded ChartWidget / TableWidget is the same class the Stat was built from.
     *
     * The inner ->schema() MUST be a closure: Filament caches page schemas, so a
     * statically-built expanded region would freeze at its first-render state.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFiltersFormContentComponent(),
                $this->getWidgetsContentComponent(),
            ]);
    }

    public function getWidgetsContentComponent(): Component
    {
        return Grid::make($this->getColumns())
            ->schema(fn (): array => [
                ...$this->getWidgetsSchemaComponents($this->getWidgets()),
                ...$this->expandedDetailComponents(),
            ]);
    }

    /**
     * @return array<Component>
     */
    protected function expandedDetailComponents(): array
    {
        $expanded = $this->expandedCardComponents();

        if ($expanded === []) {
            return [];
        }

        return [
            Group::make([...$expanded, ...$this->cardQueryComponents()])
                ->id(self::EXPANDED_DETAIL_ELEMENT_ID)
                ->columnSpanFull(),
        ];
    }

    /**
     * The raw SQL behind the expanded card's headline value, for admin impersonators only.
     *
     * The gate is the FIRST statement on purpose: for anyone else this returns before the card is
     * constructed, so no statement is ever built and the SQL cannot reach the response because it
     * is never created. That is stronger than hiding a component whose data was already computed.
     *
     * @return array<Component>
     */
    protected function cardQueryComponents(): array
    {
        if (session()->missing('admin_impersonator')) {
            return [];
        }

        $class = $this->cardWidgets()[$this->expandedCard] ?? null;

        if ($class === null) {
            return [];
        }

        $card = new $class;

        if (\property_exists($card, 'date')) {
            $card->date = $this->resolvePageDateToggle();
        }

        $supported = $card instanceof ExplainsCardQuery;
        $statements = [];
        $error = null;

        try {
            $statements = $supported ? $card->getCardQueryStatements() : [];
        } catch (\Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        return [
            View::make('filament.widgets.dashboard.card-query')
                ->key($class.'-query')
                ->columnSpanFull()
                ->viewData([
                    'statements' => $statements,
                    'error' => $error,
                    'supported' => $supported,
                    'connection' => 'clarity_clickhouse',
                    'database' => config('database.connections.clarity_clickhouse.database'),
                    'driver' => config('database.connections.clarity_clickhouse.driver'),
                    'environment' => config('app.env'),
                    'timezone' => auth()->user()->timezone ?? 'UTC',
                ]),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components(
                DateTogglesFilter::make('date')
                    ->track('date-filter', 'dashboard')
                    ->defaultDate('this_month')
                    ->dateToggles([
                        'today' => 'Today',
                        'last_7d' => 'Last 7d',
                        'this_month' => 'This Month',
                        'last_month' => 'Last Month',
                    ])
                    ->bare()
                    ->showAdvanced(false)
                    ->modifyQueryUsing(null)
                    ->getCustomSchema()
            );
    }

    public function updatedFilters(): void
    {
        $this->dispatch('refresh-dashboard', date: $this->resolvePageDateToggle());
    }

    /**
     * Flat `date_toggles`, or the same key nested under the filter name `date`.
     */
    protected function resolvePageDateToggle(): string
    {
        $filters = \is_array($this->filters) ? $this->filters : [];
        $nested = \is_array($filters['date'] ?? null) ? $filters['date'] : [];

        foreach ([$filters['date_toggles'] ?? null, $nested['date_toggles'] ?? null] as $date) {
            if (\is_string($date) && $date !== '') {
                return $date;
            }
        }

        return 'this_month';
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(RoleEnum::ADMIN->value, RoleEnum::USER->value);
    }

    public function mountCanAuthorizeAccess(): void
    {
        // Overridden: moved to mount() method.
    }
}

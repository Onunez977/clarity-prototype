<?php

namespace App\Filament\Tables\Filters;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use Closure;
use Filament\Forms;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DateTogglesFilter extends Filter
{
    protected string|Closure|int|array|null $columnSpan = 'full';

    protected ?string $defaultDate = 'last_30d';

    protected bool $ignoreRelationship = false;

    protected bool $showDatePickers = false;

    protected bool $advanced = true;

    protected bool $hasTable = true;

    protected bool $bare = false;

    protected ?string $trackName = null;

    protected ?string $trackPage = null;

    protected bool $colSpan = true;

    protected bool $dateTime = true;

    protected mixed $helperText = null;

    protected array $dates = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7d' => 'Last 7d',
        'last_30d' => 'Last 30d',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'year' => 'Year',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([]);

        $this->modifyQueryUsing(function (Builder $query, array $data): Builder {
            // Find relationship (if given).
            $relationship = null;
            $column = $this->getName();

            if (! $this->ignoreRelationship) {
                $parts = \explode('.', $column);
                if (\count($parts) > 1) {
                    $relationship = $parts[0];
                    $column = $parts[1];
                }
            }

            if ($relationship && $query->getModel()->getTable() === $relationship) {
                // Ignore self-referencing relationships.
                $relationship = null;
            }

            return $query
                ->when(
                    $relationship,
                    fn ($query) => $query
                        ->when(
                            $data['start'] && $data['end'],
                            fn (Builder $query): Builder => $query->whereHas($relationship, fn ($subQuery) => $subQuery->whereBetween($column, [convertToUtc($data['start']), convertToUtc($data['end'])])),
                        )
                        ->when(
                            $data['start'] && ! $data['end'],
                            fn (Builder $query): Builder => $query->whereHas($relationship, fn ($subQuery) => $subQuery->where($column, '>=', convertToUtc($data['start']))),
                        )
                        ->when(
                            $data['end'] && ! $data['start'],
                            fn (Builder $query): Builder => $query->whereHas($relationship, fn ($subQuery) => $subQuery->where($column, '<=', convertToUtc($data['end'])))
                        ),
                    fn ($query) => $query
                        ->when(
                            $data['start'] && $data['end'],
                            fn (Builder $query): Builder => $query->whereBetween($this->getName(), [convertToUtc($data['start']), convertToUtc($data['end'])]),
                        )
                        ->when(
                            $data['start'] && ! $data['end'],
                            fn (Builder $query): Builder => $query->where($this->getName(), '>=', convertToUtc($data['start'])),
                        )
                        ->when(
                            $data['end'] && ! $data['start'],
                            fn (Builder $query): Builder => $query->where($this->getName(), '<=', convertToUtc($data['end'])),
                        )
                );
        });

        $this->indicateUsing(function (array $data): ?array {
            if ($data['start'] || $data['end']) {
                $dateIndicator = Str::of($this->getName())->afterLast('.')->headline()->toString().': ';

                if ($data['start'] && ! $data['end']) {
                    $dateIndicator .= 'starting at '.prettyDatetime($data['start']);
                } elseif (! $data['start'] && $data['end']) {
                    $dateIndicator .= 'ending at '.prettyDatetime($data['end']);
                } else {
                    $dateIndicator .= prettyDatetime($data['start']).' - '.prettyDatetime($data['end']);
                }

                return [$dateIndicator];
            }

            return null;
        });
    }

    public function schema(array|Closure|null $schema): static
    {
        $this->schema = \array_merge(
            $this->evaluate($this->schema) ?? [],
            $this->getCustomSchema()
        );

        return $this;
    }

    public function getCustomSchema(): array
    {
        if ($this->bare) {
            return $this->formComponents();
        } else {
            return [
                Fieldset::make()
                    ->label(fn (): string => 'Filter by '.Str::headline($this->getLabel()))
                    ->columnSpanFull()
                    ->schema($this->formComponents()),
            ];
        }
    }

    private function formComponents(): array
    {
        $startComponent = Forms\Components\DateTimePicker::make('start');
        $endComponent = Forms\Components\DateTimePicker::make('end');

        if (! $this->dateTime) {
            $startComponent = Forms\Components\DatePicker::make('start');
            $endComponent = Forms\Components\DatePicker::make('end');
        }

        return [
            Forms\Components\ToggleButtons::make('date_toggles')
                ->hiddenLabel()
                ->inline()
                ->live()
                ->when(fn () => $this->colSpan, fn ($component) => $component->columnSpanFull())
                ->default(fn (): ?string => $this->getDefaultDate())
                ->options(fn (): array => $this->getDateToggles())
                ->helperText(fn (): mixed => $this->evaluate($this->helperText))
                ->extraAttributes(array_filter([
                    'data-track' => $this->trackName,
                    'data-track-page' => $this->trackPage,
                ]))
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    if ($state === 'advanced') {
                        // Show the date pickers.
                        $this->showDatePickers = true;

                        return;
                    }

                    $start = $this->resolveStartDate($state);
                    $set('start', $state === 'all' ? null : $start?->format('Y-m-d H:i:s'));

                    $end = $this->resolveEndDate($state);
                    $set('end', $state === 'all' ? null : $end?->format('Y-m-d H:i:s'));
                }),

            $startComponent
                ->label('Start')
                ->columnSpanFull()
                ->seconds(false)
                ->dehydratedWhenHidden()
                ->visible(fn (Get $get): bool => $this->showDatePickers($get('date_toggles')))
                ->timezone($this->viewerTimezone())
                ->default(fn () => $this->getDefaultDate() === 'all' ? null : $this->resolveStartDate($this->getDefaultDate())),

            $endComponent
                ->label('End')
                ->columnSpanFull()
                ->seconds(false)
                ->dehydratedWhenHidden()
                ->visible(fn (Get $get): bool => $this->showDatePickers($get('date_toggles')))
                ->timezone($this->viewerTimezone())
                ->default(fn () => $this->getDefaultDate() === 'all' ? null : now(tz: $this->viewerTimezone()))
                ->maxDate(now(tz: $this->viewerTimezone())),
        ];
    }

    public function dateToggles(array $dates): self
    {
        $this->dates = $dates;

        return $this;
    }

    public function getDateToggles(): array
    {
        return \array_merge(
            $this->dates,
            $this->advanced ? [
                'advanced' => 'Advanced',
            ] : []
        );
    }

    public function defaultDate(?string $state): self
    {
        $this->defaultDate = $state;

        return $this;
    }

    public function getDefaultDate(): ?string
    {
        return $this->defaultDate;
    }

    /**
     * Preset toggles hide the date pickers, so Filament can apply Search while
     * start/end are still the previous window. Bounded presets own those bounds.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function stateWithPresetBounds(array $state): array
    {
        $toggle = $state['date_toggles'] ?? null;

        if (! is_string($toggle) || $toggle === '' || $toggle === 'advanced' || $toggle === 'all') {
            return $state;
        }

        if (! array_key_exists($toggle, $this->dates)) {
            return $state;
        }

        $start = $this->resolveStartDate($toggle);
        $end = $this->resolveEndDate($toggle);

        $state['start'] = $start?->format('Y-m-d H:i:s');
        $state['end'] = $end?->format('Y-m-d H:i:s');

        return $state;
    }

    /**
     * Constructable viewer zone for every PHP, Carbon, and Filament feed on this filter.
     *
     * `users.dti` is unvalidated and still stores fleet BC aliases. Feeding the raw
     * value to `now(tz:)` or `DateTimePicker::timezone()` throws
     * `Carbon\Exceptions\InvalidTimeZoneException` while the dashboard filters form
     * is built, before any card query runs. {@see ScanPlateStatsBatchQuery::safeTimezone()}
     * remaps an unconstructable alias to its canonical IANA id and garbage to UTC,
     * and it never throws.
     */
    public function viewerTimezone(): string
    {
        return ScanPlateStatsBatchQuery::safeTimezone((string) (auth()->user()->timezone ?? 'UTC'));
    }

    protected function resolveStartDate(?string $state = null): ?Carbon
    {
        $now = now()->setTimezone($this->viewerTimezone());

        return $state
            ? match ($state) {
                'today' => $now->startOfDay(),
                'yesterday' => $now->subDay()->startOfDay(),
                'last_7d', '7_days' => $now->subDays(7)->startOfDay(),
                '15_days' => $now->subDays(15)->startOfDay(),
                'last_30d', '30_days' => $now->subDays(30)->startOfDay(),
                'last_90d', '90_days' => $now->subDays(90)->startOfDay(),
                'this_month' => $now->startOfMonth()->startOfDay(),
                'last_month' => $now->startOfMonth()->subMonth()->startOfDay(),
                'last_3m', '3_months' => $now->startOfMonth()->subMonths(3)->startOfDay(),
                'last_6m', '6_months' => $now->startOfMonth()->subMonths(6)->startOfDay(),
                'ytd' => $now->startOfYear()->startOfDay(),
                'year' => $now->subYear()->startOfDay(),
                default => $now->subMonth()->startOfDay(),
            }
        : null;
    }

    protected function resolveEndDate(?string $state = null): ?Carbon
    {
        $now = now()->setTimezone($this->viewerTimezone());

        return $state
            ? match ($state) {
                'yesterday' => $now->subDay()->endOfDay(),
                'this_month' => $now->endOfMonth()->endOfDay(),
                'last_month' => $now->startOfMonth()->subMonth()->endOfMonth()->endOfDay(),
                default => now(),
            }
        : null;
    }

    public function showDatePickers(?string $dateTogglesState = null): bool
    {
        if (! $this->advanced) {
            return false;
        }

        if ($this->showDatePickers) {
            return true;
        }

        // If state is passed directly (from form Get helper), use it
        if ($dateTogglesState !== null) {
            return $dateTogglesState === 'advanced';
        }

        // Only try to get state from table if we have one
        if ($this->hasTable) {
            return ($this->getState()['date_toggles'] ?? null) === 'advanced';
        }

        return false;
    }

    public function showAdvanced(bool $advanced = true, bool $hasTable = true): self
    {
        $this->advanced = $advanced;
        $this->hasTable = $hasTable;

        return $this;
    }

    public function bare(bool $bare = true): static
    {
        $this->bare = $bare;

        return $this;
    }

    public function colSpan(bool $full = true): static
    {
        $this->colSpan = $full;

        return $this;
    }

    public function dateTime(bool $dateTime = true): static
    {
        $this->dateTime = $dateTime;

        return $this;
    }

    public function ignoreRelationship(bool $ignore = true): self
    {
        $this->ignoreRelationship = $ignore;

        return $this;
    }

    public function helperText(string|Closure $text): self
    {
        $this->helperText = $text;

        return $this;
    }

    public function track(string $name, ?string $page = null): static
    {
        $this->trackName = $name;
        $this->trackPage = $page;

        return $this;
    }
}

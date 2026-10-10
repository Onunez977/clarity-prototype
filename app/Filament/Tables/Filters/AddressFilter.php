<?php

namespace App\Filament\Tables\Filters;

use App\Clickhouse\Models\ClickhouseModel;
use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Database\PostgisFunctions\ST;
use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Filters\Filter;
use Geocodio\Geocodio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class AddressFilter extends Filter
{
    protected string|Closure|int|array|null $columnSpan = 'full';

    protected ?string $trackName = null;

    /** Filter radius in miles — wide enough to absorb geocoding drift. */
    protected const RADIUS_MILES = 0.15;

    protected const MILES_PER_DEGREE = 69;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([]);

        // Find relationship (if given).
        $relationship = null;
        $column = $this->getName();

        $parts = \explode('.', $column);
        if (\count($parts) > 1) {
            $relationship = $parts[0];
            $column = $parts[1];
        }

        $this->modifyQueryUsing(function (Builder $query, array $data) use ($relationship, $column): Builder {
            if ($data['lat'] && $data['lon']) {
                if ($query->getModel() instanceof ClickhouseModel) {
                    $lat = (float) $data['lat'];
                    $lon = (float) $data['lon'];

                    $latRadius = self::RADIUS_MILES / self::MILES_PER_DEGREE;
                    $lonRadius = self::RADIUS_MILES / abs(cos(deg2rad($lat)) * self::MILES_PER_DEGREE);

                    return $query
                        ->whereRaw('lat BETWEEN '.($lat - $latRadius).' AND '.($lat + $latRadius))
                        ->whereRaw('lng BETWEEN '.($lon - $lonRadius).' AND '.($lon + $lonRadius));
                }

                $column = $this->formatColumn($column);
                $point = Point::makeGeodetic(\trim($data['lat']), \trim($data['lon']));
                $radius = self::RADIUS_MILES / self::MILES_PER_DEGREE;

                return $query
                    ->when(
                        $relationship,
                        fn ($query) => $query->whereHas($relationship, fn ($subQuery) => $subQuery->stWhere(ST::dWithinGeometry($column, $point, $radius), DB::raw('true'))),
                        fn ($query) => $query->stWhere(ST::dWithinGeometry($column, $point, $radius), DB::raw('true'))
                    );
            }

            return $query;
        });

        $this->indicateUsing(function (array $data): ?array {
            if ($data['address']) {
                return [
                    'Within 0.1 miles of:<br/>"'.$data['address'].'"',
                ];
            } elseif ($data['lat'] && $data['lon']) {
                return [
                    'Within 0.1 miles of:<br/>"'.$data['lat'].', '.$data['lon'].'"',
                ];
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
        return [
            Section::make('Filter by Location')
                ->columns([
                    'default' => 1,
                    'xl' => 2,
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->description(fn () => $this->getIndicators() !== [] ? new HtmlString($this->getIndicators()[0]?->getLabel()) : null)
                ->schema([
                    Forms\Components\TextInput::make('address')
                        ->label('Address')
                        ->live(onBlur: true)
                        ->columnSpanFull()
                        ->helperText('Search within 0.1 miles of a specific location.')
                        ->extraInputAttributes($this->trackName ? ['data-track' => $this->trackName] : [])
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if ($state) {
                                try {
                                    // Geocode into Lat/Lon
                                    $geocoder = new Geocodio;
                                    $geocoder->setApiKey(config('geocodio.api_key'));

                                    $response = cache()->remember(
                                        'geocode-'.md5($state),
                                        now()->addDay(),
                                        fn () => (array) $geocoder->geocode(\trim($state), [], 1, 'simple'),
                                    );

                                    $set('lat', $response['lat'] ?? null);
                                    $set('lon', $response['lng'] ?? null);
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title('Error')
                                        ->body('There was an error trying to geocode the address: "'.$state.'".')
                                        ->warning()
                                        ->send();
                                }
                            }
                        }),

                    Forms\Components\TextInput::make('lat')
                        ->label('Latitude')
                        ->live(debounce: 750)
                        ->numeric()
                        ->step(0.0001),

                    Forms\Components\TextInput::make('lon')
                        ->label('Longitude')
                        ->live(debounce: 750)
                        ->numeric()
                        ->step(0.0001),
                ]),
        ];
    }

    public function track(string $name): static
    {
        $this->trackName = $name;

        return $this;
    }

    protected function formatColumn(string $column): string
    {
        if (Str::lower($column) !== $column) {
            $column = (string) str($column)->wrap('"');
        }

        return $column;
    }
}

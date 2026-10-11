<?php

namespace App\Filament\Resources;

use App\Actions\Activity\Log\HitsSearch;
use App\Actions\Activity\Log\HotlistsSearch;
use App\Actions\Activity\Log\MissedOpportunitiesSearch;
use App\Actions\Activity\Log\OttoHitsSearch;
use App\Actions\Activity\Log\OttoHotlistsSearch;
use App\Actions\Activity\Log\OttoScansSearch;
use App\Actions\Activity\Log\OttoScoutDriverPositionsSearch;
use App\Actions\Activity\Log\OttoZonesSearch;
use App\Actions\Activity\Log\ScansSearch;
use App\Actions\Activity\Log\ScoutDriverPositionsSearch;
use App\Actions\Activity\Log\ZonesSearch;
use App\Enums\FeatureEnum;
use App\Filament\Resources\ActivityResource\Pages;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'Activity';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fieldset::make('Technical Detail')
                    ->columns(1)
                    ->schema([
                        Infolists\Components\TextEntry::make('log_name')
                            ->label('Device Info')
                            ->formatStateUsing(fn (Model $record): string => $record->properties?->get('_request')['userAgent'] ?? 'N/A'),

                        Infolists\Components\TextEntry::make('description')
                            ->label('IP Address')
                            ->formatStateUsing(fn (Model $record): string => $record->properties?->get('_request')['clientIp'] ?? 'N/A'),
                    ]),

                Infolists\Components\KeyValueEntry::make('properties.plateNumber')
                    ->label('Plates')
                    ->keyLabel('Key')
                    ->valueLabel('Plate')
                    ->visible(fn (mixed $state): mixed => $state),
            ]);
    }

    public static function table(Table $table): Table
    {
        $orgUsers = User::query()
            ->withTrashed()
            ->where('org_id', auth()->user()->org_id)
            ->get()
            ->mapWithKeys(fn ($user) => [$user->id => $user->getFilamentName().' ('.($user->email ?? 'Invalid Email').')'])
            ->sort();

        $availableLogs = \array_merge([
            ScansSearch::$log_name => ScansSearch::$log_name,
            HitsSearch::$log_name => HitsSearch::$log_name,
            HotlistsSearch::$log_name => HotlistsSearch::$log_name,
            ZonesSearch::$log_name => ZonesSearch::$log_name,
            ScoutDriverPositionsSearch::$log_name => ScoutDriverPositionsSearch::$log_name,
            OttoScansSearch::$log_name => OttoScansSearch::$log_name,
            OttoHitsSearch::$log_name => OttoHitsSearch::$log_name,
            OttoHotlistsSearch::$log_name => OttoHotlistsSearch::$log_name,
            OttoZonesSearch::$log_name => OttoZonesSearch::$log_name,
            OttoScoutDriverPositionsSearch::$log_name => OttoScoutDriverPositionsSearch::$log_name,
        ], Feature::active(FeatureEnum::MISSED_OPPORTUNITY->slug())
            ? [MissedOpportunitiesSearch::$log_name => MissedOpportunitiesSearch::$log_name]
            : []
        );

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('description')
                    ->label('Description'),

                Tables\Columns\TextColumn::make('causer_id')
                    ->label('Who')
                    ->formatStateUsing(fn (mixed $state) => User::find($state)?->email ?? 'N/A'),

                Tables\Columns\TextColumn::make('log_name')
                    ->label('Log')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('properties')
                    ->label('Not on Hotlist')
                    ->hidden(fn (Component $livewire): bool => ($livewire->tableFilters['filters']['plateHotlist'] ?? 'false') === 'true')
                    ->formatStateUsing(fn (Model $record): int => \count($record->properties?->get('platesNotOnHotlist') ?? [])),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->timezone(auth()->user()->timezone)
                    ->dateTime(config('app.date_format'))
                    ->description(fn (Model $record): mixed => $record?->created_at?->diffForHumans() ?? null)
                    ->sortable(),
            ])
            ->filters([
                Filter::make('filters')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('log')
                            ->label('Search by Log')
                            ->options($availableLogs),

                        Select::make('user')
                            ->label('Search by User')
                            ->options($orgUsers->toArray()),

                        PlateFilter::make('plate')
                            ->label('Search by Plate'),

                        Forms\Components\DateTimePicker::make('start_date')
                            ->label('Start Date')
                            ->seconds(false)
                            ->timezone(auth()->user()->timezone)
                            ->default(now(tz: auth()->user()->timezone)->startOfDay()),

                        Forms\Components\DateTimePicker::make('end_date')
                            ->label('End Date')
                            ->seconds(false)
                            ->timezone(auth()->user()->timezone)
                            ->default(now(tz: auth()->user()->timezone)->endOfDay())
                            ->maxDate(now(tz: auth()->user()->timezone)->endOfDay()),

                        Forms\Components\Toggle::make('plateHotlist')
                            ->label('Plate not on Hotlist')
                            ->inline()
                            ->helperText('A search was made where plate is not on an open order.'),
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];

                        if ($data['log']) {
                            $indicators[] = 'Log: '.$data['log'];
                        }

                        if ($data['plate']) {
                            $indicators[] = 'Plate: '.$data['plate'];
                        }

                        if ($data['start_date'] || $data['end_date']) {
                            $dateIndicator = 'Date: ';

                            if ($data['start_date'] && ! $data['end_date']) {
                                $dateIndicator .= 'starting at '.prettyDatetime($data['start_date']);
                            } elseif (! $data['start_date'] && $data['end_date']) {
                                $dateIndicator .= 'ending at '.prettyDatetime($data['end_date']);
                            } else {
                                $dateIndicator .= prettyDatetime($data['start_date']).' - '.prettyDatetime($data['end_date']);
                            }

                            $indicators[] = $dateIndicator;
                        }

                        return $indicators !== []
                            ? $indicators
                            : null;
                    })
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['log'],
                            fn (Builder $query, $log): Builder => $query->where('log_name', $log),
                        )
                        ->when(
                            $data['user'],
                            fn (Builder $query, $user): Builder => $query->where('causer_id', $user),
                        )
                        ->when(
                            $data['plate'],
                            fn (Builder $query, $plate): Builder => $query->whereJsonContains('properties->plateNumber', Str::of($plate)->trim()->upper()->toString()),
                        )
                        ->when(
                            $data['plateHotlist'],
                            fn (Builder $query): Builder => $query->whereRaw('properties->\'platesNotOnHotlist\' IS NOT NULL'),
                        )
                        ->when(
                            $data['start_date'] && $data['end_date'],
                            fn (Builder $query): Builder => $query->whereBetween('created_at', [convertToUtc($data['start_date']), convertToUtc($data['end_date'])]),
                        )
                        ->when(
                            $data['start_date'] && ! $data['end_date'],
                            fn (Builder $query): Builder => $query->where('created_at', '>=', convertToUtc($data['start_date'])),
                        )
                        ->when(
                            $data['end_date'] && ! $data['start_date'],
                            fn (Builder $query): Builder => $query->where('created_at', '<=', convertToUtc($data['end_date'])),
                        )
                    ),
            ])
            ->recordActions([
                ViewAction::make('view')
                    ->label('')
                    ->tooltip('View Technical Details'),
            ])
            ->modifyQueryUsing(function (Builder $query) use ($orgUsers, $availableLogs) {
                return $query
                    ->where('causer_type', 'user')
                    ->whereIn('causer_id', $orgUsers->keys())
                    ->whereIn('log_name', \array_keys($availableLogs));
            })
            ->defaultSort('created_at', 'desc')
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivities::route('/'),
        ];
    }
}

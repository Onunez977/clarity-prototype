<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\FeatureEnum;
use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Filament\Actions\ZoneKmlImportAction;
use App\Filament\Actions\ZoneQuickEditAction;
use App\Filament\Actions\ZoneReassignAction;
use App\Filament\Pages\ManageZones;
use App\Filament\Resources\ZoneResource\Pages;
use App\Filament\Tables\Columns\DateTimeColumn;
use App\Filament\Tables\Filters\ZoneDriverFilter;
use App\Models\Zone;
use App\Services\Zones\ZoneBroadcaster;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

class ZoneResource extends Resource
{
    protected static ?string $model = Zone::class;

    protected static ?string $slug = 'zones';

    protected static ?string $navigationLabel = 'Zones';

    protected static ?string $modelLabel = 'Zone';

    protected static ?string $pluralModelLabel = 'Zones';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static string|\UnitEnum|null $navigationGroup = 'Scout';

    public static function getNavigationBadge(): ?string
    {
        return 'Pro';
    }

    public static function canAccess(): bool
    {
        return (Feature::active(FeatureEnum::CLARITY_SCOUT->slug()) || session()->has('admin_impersonator'))
            && (bool) auth()->user()?->can('viewAny', Zone::class);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Zone::class) ?? false;
    }

    /**
     * Org-isolate direct-URL record resolution (row actions, restore) and keep
     * trashed rows resolvable so the Restore action can reach them.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->forOrg(auth()->user()->org_id)
            ->withoutGlobalScope(SoftDeletingScope::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color')
                    ->label(''),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('is_no_scan_zone')
                    ->label('No-Scan')
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(fn (Zone $record): ?string => $record->is_no_scan_zone ? 'No-Scan' : null),

                Tables\Columns\TextColumn::make('users.name')
                    ->label('Assigned Drivers')
                    ->badge()
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->placeholder('Unassigned'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Drivers')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created by')
                    ->toggleable(isToggledHiddenByDefault: true),

                DateTimeColumn::make('created_at')
                    ->label('Created')
                    ->sortable()
                    ->toggleable(),

                DateTimeColumn::make('updated_at')
                    ->label('Updated')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('assigned_driver')
                    ->schema([
                        ZoneDriverFilter::make('driver')
                            ->label('Assigned driver'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['driver'] ?? null)
                        ? $query->whereHas('users', fn (Builder $users) => $users->whereIn('users.id', ZoneDriverFilter::format($data['driver'])))
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['driver'] ?? null)
                        ? ZoneDriverFilter::indicator($data['driver'])
                        : null),

                Tables\Filters\TernaryFilter::make('unassigned')
                    ->label('Driver assignment')
                    ->placeholder('All zones')
                    ->trueLabel('Unassigned only')
                    ->falseLabel('Assigned only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->doesntHave('users'),
                        false: fn (Builder $query): Builder => $query->has('users'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Tables\Filters\TernaryFilter::make('is_no_scan_zone')
                    ->label('No-scan zones'),

                Tables\Filters\SelectFilter::make('color')
                    ->label('Color')
                    ->options(Zone::PRESET_COLORS + [Zone::NO_SCAN_COLOR => 'No-Scan Gray']),

                Tables\Filters\TrashedFilter::make()
                    ->label('Deleted Zones')
                    ->placeholder('Without Deleted Zones')
                    ->trueLabel('With Deleted Zones')
                    ->falseLabel('Only Deleted Zones'),
            ])
            ->toolbarActions([
                Action::make('create')
                    ->label('New Zone')
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => ManageZones::getUrl())
                    ->visible(fn (): bool => static::canCreate()),

                ZoneKmlImportAction::make()
                    ->visible(fn (): bool => static::canCreate()),

                ZoneReassignAction::bulkAction(),

                BulkAction::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete selected zones?')
                    ->modalDescription('This soft-deletes each zone, unassigns its drivers, and notifies them in real time.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $broadcaster = app(ZoneBroadcaster::class);

                        foreach ($records as $zone) {
                            Gate::authorize('delete', $zone);

                            $assignedUserIds = $zone->users()->pluck('users.id')->all();
                            $deletedId = $zone->id;
                            $deletedName = $zone->name;

                            $zone->delete();

                            $broadcaster->broadcastDeleted($deletedId, $deletedName, $assignedUserIds);
                        }

                        Analytics::track(
                            ScoutAnalyticsEvent::ZoneDelete->value,
                            auth()->user(),
                            ['action' => 'delete', 'count' => $records->count()],
                            ScoutAnalyticsPage::Zones->value,
                        );

                        Notification::make()
                            ->title('Zones deleted')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('')
                    ->size('lg')
                    ->icon('heroicon-o-map')
                    ->tooltip('Open in map editor')
                    ->url(fn (Zone $record): string => ManageZones::getUrl(['zone' => $record->id]))
                    ->visible(fn (Zone $record): bool => ! $record->trashed()),

                ZoneReassignAction::tableAction()
                    ->visible(fn (Zone $record): bool => ! $record->trashed()),

                ZoneQuickEditAction::make(),

                DeleteAction::make()
                    ->label('')
                    ->size('lg')
                    ->tooltip('Delete Zone')
                    ->action(function (Zone $record): void {
                        $assignedUserIds = $record->users()->pluck('users.id')->all();
                        $deletedId = $record->id;
                        $deletedName = $record->name;

                        $record->delete();

                        app(ZoneBroadcaster::class)->broadcastDeleted($deletedId, $deletedName, $assignedUserIds);

                        Analytics::track(
                            ScoutAnalyticsEvent::ZoneDelete->value,
                            auth()->user(),
                            ['action' => 'delete', 'zone_id' => $deletedId],
                            ScoutAnalyticsPage::Zones->value,
                        );

                        Notification::make()
                            ->title('Zone deleted')
                            ->success()
                            ->send();
                    }),

                RestoreAction::make()
                    ->label('')
                    ->size('lg')
                    ->tooltip('Restore Zone')
                    ->visible(fn (Zone $record): bool => $record->trashed())
                    ->after(function (Zone $record): void {
                        app(ZoneBroadcaster::class)->broadcastUpdatedTo(
                            $record,
                            $record->users()->pluck('users.id')->all(),
                        );

                        Analytics::track(
                            ScoutAnalyticsEvent::ZoneRestore->value,
                            auth()->user(),
                            ['action' => 'restore', 'zone_id' => $record->id],
                            ScoutAnalyticsPage::Zones->value,
                        );
                    }),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->forOrg(auth()->user()->org_id)
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->withCount('users')
                ->with(['users:id,name', 'creator:id,name']))
            ->recordClasses(fn (Zone $record): ?string => $record->deleted_at ? '!bg-red-200/20 dark:!bg-red-900/20' : null)
            ->defaultSort('name', 'asc')
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZones::route('/'),
        ];
    }
}

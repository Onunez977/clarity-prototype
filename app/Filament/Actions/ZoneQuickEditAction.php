<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Models\Zone;
use App\Services\Zones\ZoneBroadcaster;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Gate;

/**
 * Slim "edit zone metadata" action for the Zones list — name, description,
 * color, and the no-scan flag only. Geometry stays on the map editor, so this
 * never touches `definition`/`geom` or the driver roster. It mirrors the map
 * editor's no-scan→gray coupling and broadcasts ZoneUpdated to assigned drivers
 * through the shared ZoneBroadcaster so the two surfaces stay in sync.
 */
class ZoneQuickEditAction
{
    public static function make(): Action
    {
        return Action::make('quickEdit')
            ->label('')
            ->size('lg')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->tooltip('Quick edit')
            ->modalHeading('Quick edit zone')
            ->modalDescription('Edit name, description, and color. Geometry is edited on the map.')
            ->modalSubmitActionLabel('Save')
            ->visible(fn (Zone $record): bool => ! $record->trashed())
            ->fillForm(fn (Zone $record): array => [
                'name' => $record->name,
                'description' => $record->description,
                'is_no_scan_zone' => $record->is_no_scan_zone,
                'color' => $record->is_no_scan_zone ? $record->color : Zone::coerceColor($record->color),
            ])
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Zone Name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Textarea::make('description')
                    ->label('Description')
                    ->rows(2)
                    ->maxLength(1000),

                Forms\Components\Toggle::make('is_no_scan_zone')
                    ->label('No Scan Zone')
                    ->helperText('Marks this area as off-limits — drivers should not enter or scan it. Displays in gray on all maps.')
                    ->live()
                    ->afterStateUpdated(function (bool $state, Set $set) {
                        if ($state) {
                            $set('color', Zone::NO_SCAN_COLOR);
                        }
                    }),

                Forms\Components\Select::make('color')
                    ->label('Map Color')
                    ->options(fn (Get $get): array => $get('is_no_scan_zone')
                        ? Zone::PRESET_COLORS + [Zone::NO_SCAN_COLOR => 'No-Scan Gray']
                        : Zone::PRESET_COLORS)
                    ->default(Zone::DEFAULT_COLOR)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live(onBlur: true)
                    ->disabled(fn (Get $get): bool => (bool) $get('is_no_scan_zone'))
                    ->dehydrated()
                    ->dehydrateStateUsing(
                        fn ($state, Get $get) => $get('is_no_scan_zone') ? Zone::NO_SCAN_COLOR : Zone::coerceColor($state)
                    ),
            ])
            ->action(function (Zone $record, array $data): void {
                Gate::authorize('update', $record);

                $record->fill([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'color' => $data['color'],
                    'is_no_scan_zone' => (bool) ($data['is_no_scan_zone'] ?? false),
                ])->save();

                app(ZoneBroadcaster::class)->broadcastUpdatedTo(
                    $record,
                    $record->users()->pluck('users.id')->all(),
                );

                Analytics::track(
                    ScoutAnalyticsEvent::ZoneEdit->value,
                    auth()->user(),
                    ['action' => 'quick-edit', 'zone_id' => $record->id],
                    ScoutAnalyticsPage::Zones->value,
                );

                Notification::make()
                    ->title('Zone saved')
                    ->success()
                    ->send();
            });
    }
}

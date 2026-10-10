<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zones\ZoneAssignmentSyncer;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * One source of truth for the "reassign drivers" flow shared by the map editor
 * (ManageZones), the Zones list per-row action, and the Zones list bulk action.
 * The reassignment itself runs through ZoneAssignmentSyncer, which already
 * broadcasts ZoneAssignmentsChanged to every added/removed driver — so reassign
 * needs no separate ZoneUpdated, matching the map page exactly.
 */
class ZoneReassignAction
{
    /**
     * The mode + kit-validated driver-select schema, identical across surfaces.
     *
     * @return array<int, Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Radio::make('mode')
                ->label('Assignment mode')
                ->options([
                    ZoneAssignmentSyncer::MODE_REPLACE => 'Replace — set this list as the zone\'s drivers',
                    ZoneAssignmentSyncer::MODE_ADD => 'Add — merge with current drivers',
                ])
                ->default(ZoneAssignmentSyncer::MODE_REPLACE)
                ->required()
                ->inline()
                ->inlineLabel(false),

            Forms\Components\Select::make('users')
                ->label('Drivers')
                ->multiple()
                ->preload()
                ->searchable()
                ->options(
                    fn () => User::query()
                        ->where('org_id', auth()->user()->org_id)
                        ->whereHas('kitSettings')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                )
                ->getOptionLabelsUsing(
                    fn (array $values): array => self::driverLabels($values)
                )
                ->rule(function () {
                    return function (string $attribute, $value, \Closure $fail) {
                        if (empty($value)) {
                            return;
                        }

                        $valid = User::query()
                            ->whereIn('id', $value)
                            ->where('org_id', auth()->user()->org_id)
                            ->whereHas('kitSettings')
                            ->pluck('id')
                            ->all();

                        if (array_diff($value, $valid) !== []) {
                            $fail('One or more selected drivers do not have a kit.');
                        }
                    };
                })
                ->helperText('Only users with an assigned scanner kit are listed.'),
        ];
    }

    /**
     * Org-scoped driver-id → name resolution for the select labels, mirroring
     * options()/rule() so a tampered Livewire state can't surface a foreign
     * org's user name. Shared by ManageZones' driver select.
     *
     * @param  array<int, int|string>  $values
     * @return array<int|string, string>
     */
    public static function driverLabels(array $values): array
    {
        return User::query()
            ->whereIn('id', $values)
            ->where('org_id', auth()->user()->org_id)
            ->whereHas('kitSettings')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Authorize and apply a reassignment to one zone. Returns the affected
     * (added + removed) driver IDs the syncer broadcast to.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    public static function apply(Zone $zone, array $data): array
    {
        Gate::authorize('update', $zone);

        return app(ZoneAssignmentSyncer::class)->syncZoneUsers(
            $zone,
            $data['users'] ?? [],
            $data['mode'] ?? ZoneAssignmentSyncer::MODE_REPLACE,
        );
    }

    /**
     * Per-row table action for the Zones list.
     */
    public static function tableAction(): Action
    {
        return Action::make('quickReassign')
            ->label('')
            ->size('lg')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->tooltip('Quick reassign drivers')
            ->modalHeading('Quick reassign drivers')
            ->modalDescription('Push selected drivers to this zone in real time. Replace swaps the current driver set; Add merges into it.')
            ->modalSubmitActionLabel('Apply')
            ->schema(self::schema())
            ->action(function (Zone $record, array $data): void {
                $affected = self::apply($record, $data);

                Analytics::track(
                    ScoutAnalyticsEvent::ZoneReassign->value,
                    auth()->user(),
                    ['action' => 'reassign', 'zone_id' => $record->id],
                    ScoutAnalyticsPage::Zones->value,
                );

                Notification::make()
                    ->title(count($affected) === 0
                        ? 'No changes — drivers already match the selection'
                        : 'Drivers reassigned ('.count($affected).' notified)')
                    ->success()
                    ->send();
            });
    }

    /**
     * Bulk action that pushes one driver set across every selected zone.
     */
    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('bulkReassign')
            ->label('Reassign Drivers')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->modalHeading('Reassign drivers across selected zones')
            ->modalDescription('Push selected drivers to every selected zone in real time. Replace swaps each zone\'s driver set; Add merges into it.')
            ->modalSubmitActionLabel('Apply')
            ->schema(self::schema())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $notified = 0;

                foreach ($records as $zone) {
                    $notified += count(self::apply($zone, $data));
                }

                Analytics::track(
                    ScoutAnalyticsEvent::ZoneReassign->value,
                    auth()->user(),
                    ['action' => 'reassign', 'count' => $records->count()],
                    ScoutAnalyticsPage::Zones->value,
                );

                Notification::make()
                    ->title($notified === 0
                        ? 'No changes — drivers already match the selection'
                        : 'Drivers reassigned across '.$records->count().' zones ('.$notified.' notifications)')
                    ->success()
                    ->send();
            });
    }
}

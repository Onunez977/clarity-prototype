<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Filament\Actions\ZoneReassignAction;
use App\Filament\Resources\ZoneResource;
use App\Models\User;
use App\Models\Zone;
use App\Services\Zones\PersistZone;
use App\Services\Zones\ZoneBroadcaster;
use App\Services\Zones\ZoneGeometryRejected;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

class ManageZones extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static ?string $navigationLabel = 'Zones';

    protected static string|\UnitEnum|null $navigationGroup = 'Scout';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.manage-zones';

    public ?int $selectedZoneId = null;

    public ?array $data = [];

    public bool $isDirty = false;

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'data.')) {
            $this->isDirty = true;
        }
    }

    public static function canAccess(): bool
    {
        return (Feature::active(FeatureEnum::CLARITY_SCOUT->slug()) || session()->has('admin_impersonator'))
            && (bool) auth()->user()?->can('viewAny', Zone::class);
    }

    /**
     * The Zones list (ZoneResource) is the navigation entry point; the map
     * editor is reached only via the list's Create/Edit deep-links.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /**
     * The map is the full editing surface (all zones visible, any one editable);
     * this links back to the tabular Zones list — the two are alternate views of
     * the same zones, toggled here and from the list's "Map view" action.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('switchToList')
                ->label('Switch to list view')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(fn (): string => ZoneResource::getUrl()),
        ];
    }

    public function mount(): void
    {
        $this->form->fill($this->defaultFormState());

        $preselect = (int) request()->query('zone', 0);
        if ($preselect > 0) {
            $existing = Zone::query()
                ->forOrg(auth()->user()->org_id)
                ->find($preselect);

            if (! $existing) {
                return;
            }

            try {
                $this->loadZone($existing->id);
            } catch (AuthorizationException) {
                // Silently leave the form blank if the user can't edit this zone.
            }

            return;
        }

        $draft = $this->draftFromQuery();

        if ($draft === null) {
            return;
        }

        try {
            $this->authorize('create', Zone::class);
        } catch (AuthorizationException) {
            return;
        }

        $this->selectedZoneId = null;
        $this->form->fill($draft);
        $this->isDirty = true;
        $this->dispatch('start-new-zone', definition: $draft['definition']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Zone Name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(2)
                            ->maxLength(1000)
                            ->live(onBlur: true),

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

                        Forms\Components\Select::make('users')
                            ->label('Assigned Kit Users')
                            ->multiple()
                            ->live()
                            ->options(
                                fn () => User::query()
                                    ->where('org_id', auth()->user()->org_id)
                                    ->whereHas('kitSettings')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->getOptionLabelsUsing(
                                fn (array $values): array => ZoneReassignAction::driverLabels($values)
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

                                    $invalid = array_diff($value, $valid);

                                    if ($invalid !== []) {
                                        $fail('One or more assigned users do not have a kit.');
                                    }
                                };
                            })
                            ->preload()
                            ->searchable()
                            ->helperText('Only users with an assigned scanner kit are listed.'),

                        Forms\Components\Hidden::make('definition')
                            ->dehydrateStateUsing(function (mixed $state): array {
                                if (is_string($state)) {
                                    $decoded = json_decode($state, true);
                                    $state = is_array($decoded) ? $decoded : [];
                                }

                                return [
                                    'polygons' => array_values($state['polygons'] ?? []),
                                    'zip_codes' => array_values(array_unique(array_map('strval', $state['zip_codes'] ?? []))),
                                ];
                            })
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail) {
                                    $value = is_string($value) ? json_decode($value, true) : $value;

                                    $hasPolygons = ! empty($value['polygons'] ?? []);
                                    $hasZips = ! empty($value['zip_codes'] ?? []);

                                    if (! $hasPolygons && ! $hasZips) {
                                        $fail('Draw a polygon or add at least one ZIP code to define the zone.');
                                    }
                                };
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function loadZone(int $id): void
    {
        $zone = Zone::query()
            ->forOrg(auth()->user()->org_id)
            ->findOrFail($id);

        $this->authorize('update', $zone);

        $this->selectedZoneId = $zone->id;

        $this->form->fill([
            'name' => $zone->name,
            'description' => $zone->description,
            'color' => $zone->is_no_scan_zone ? $zone->color : Zone::coerceColor($zone->color),
            'is_no_scan_zone' => $zone->is_no_scan_zone,
            'users' => $zone->users()->pluck('users.id')->all(),
            'definition' => [
                'polygons' => $zone->definition['polygons'] ?? [],
                'zip_codes' => $zone->definition['zip_codes'] ?? [],
            ],
        ]);

        $this->isDirty = false;

        $this->dispatch('zone-selected', zoneId: $zone->id, definition: $this->data['definition'] ?? []);
    }

    public function startNewZone(): void
    {
        $this->authorize('create', Zone::class);

        $this->selectedZoneId = null;
        $this->form->fill($this->defaultFormState());
        $this->isDirty = false;

        $this->dispatch('start-new-zone', definition: $this->data['definition'] ?? ['polygons' => [], 'zip_codes' => []]);
    }

    public function cancel(): void
    {
        $this->selectedZoneId = null;
        $this->form->fill($this->defaultFormState());
        $this->isDirty = false;

        $this->dispatch('cancel-edit');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $isCreate = $this->selectedZoneId === null;

        $attributes = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'],
            'is_no_scan_zone' => (bool) ($data['is_no_scan_zone'] ?? false),
            'definition' => $data['definition'] ?? ['polygons' => [], 'zip_codes' => []],
            'user_ids' => array_map('intval', $data['users'] ?? []),
        ];

        try {
            if ($isCreate) {
                $this->authorize('create', Zone::class);

                $zone = app(PersistZone::class)->create(
                    auth()->user(),
                    $attributes,
                    ScoutAnalyticsPage::ManageZones,
                );
            } else {
                $zone = Zone::query()
                    ->forOrg(auth()->user()->org_id)
                    ->findOrFail($this->selectedZoneId);

                $this->authorize('update', $zone);

                $zone = app(PersistZone::class)->update(
                    $zone,
                    auth()->user(),
                    $attributes,
                    ScoutAnalyticsPage::ManageZones,
                );
            }
        } catch (ZoneGeometryRejected $exception) {
            // `definition` is a Hidden component with no error slot and this page renders
            // no form-level error region, so the ValidationException alone would reject
            // the save with nothing at all on screen.
            Notification::make()
                ->title('Zone not saved')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw ValidationException::withMessages(['data.definition' => $exception->getMessage()]);
        }

        $this->selectedZoneId = $zone->id;
        $this->isDirty = false;

        Notification::make()
            ->title('Zone saved')
            ->success()
            ->send();

        $this->dispatch('zone-saved', zoneId: $zone->id);
    }

    public function delete(): void
    {
        if ($this->selectedZoneId === null) {
            return;
        }

        $zone = Zone::query()
            ->forOrg(auth()->user()->org_id)
            ->findOrFail($this->selectedZoneId);

        $this->authorize('delete', $zone);

        $deletedId = $zone->id;
        $deletedName = $zone->name;
        $assignedUserIds = $zone->users()->pluck('users.id')->all();

        $zone->delete();

        app(ZoneBroadcaster::class)->broadcastDeleted($deletedId, $deletedName, $assignedUserIds);

        $this->selectedZoneId = null;
        $this->form->fill($this->defaultFormState());
        $this->isDirty = false;

        Notification::make()
            ->title('Zone deleted')
            ->success()
            ->send();

        $this->dispatch('zone-deleted', zoneId: $deletedId);
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->color('danger')
            ->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->modalHeading('Delete this zone?')
            ->modalDescription('This will remove the zone and unassign it from all users.')
            ->action('delete')
            ->visible(fn (): bool => $this->selectedZoneId !== null);
    }

    public function quickReassignAction(): Action
    {
        return Action::make('quickReassign')
            ->label('Quick reassign')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->modalHeading('Quick reassign drivers')
            ->modalDescription('Push selected drivers to this zone in real time. Replace swaps the current driver set; Add merges into it.')
            ->modalSubmitActionLabel('Apply')
            ->visible(fn (): bool => $this->selectedZoneId !== null)
            ->schema(ZoneReassignAction::schema())
            ->action(function (array $data): void {
                $zone = Zone::query()
                    ->forOrg(auth()->user()->org_id)
                    ->findOrFail($this->selectedZoneId);

                $affected = ZoneReassignAction::apply($zone, $data);

                $this->form->fill([
                    ...$this->form->getRawState(),
                    'users' => $zone->users()->pluck('users.id')->all(),
                ]);

                Analytics::track(
                    ScoutAnalyticsEvent::ZoneReassign->value,
                    auth()->user(),
                    ['action' => 'reassign', 'zone_id' => $zone->id],
                    ScoutAnalyticsPage::ManageZones->value,
                );

                Notification::make()
                    ->title(count($affected) === 0
                        ? 'No changes — drivers already match the selection'
                        : 'Drivers reassigned ('.count($affected).' notified)')
                    ->success()
                    ->send();

                $this->dispatch('zone-saved', zoneId: $zone->id);
            });
    }

    /**
     * @return array{
     *     name: ?string,
     *     description: ?string,
     *     color: string,
     *     is_no_scan_zone: bool,
     *     users: list<int>,
     *     definition: array{polygons: list<mixed>, zip_codes: list<string>}
     * }|null
     */
    private function draftFromQuery(): ?array
    {
        $name = trim((string) request()->query('name', ''));
        $description = trim((string) request()->query('description', ''));
        $color = trim((string) request()->query('color', ''));
        $zipsRaw = trim((string) request()->query('zips', ''));
        $noScan = request()->query('no_scan');

        if ($name === '' && $description === '' && $color === '' && $zipsRaw === '' && $noScan === null) {
            return null;
        }

        $isNoScan = in_array((string) $noScan, ['1', 'true', 'yes'], true);
        $zips = $zipsRaw === ''
            ? []
            : array_values(array_unique(array_filter(preg_split('/[,\s]+/', $zipsRaw) ?: [])));

        return [
            'name' => $name !== '' ? $name : null,
            'description' => $description !== '' ? $description : null,
            'color' => $isNoScan ? Zone::NO_SCAN_COLOR : Zone::colorFromHint($color !== '' ? $color : null),
            'is_no_scan_zone' => $isNoScan,
            'users' => [],
            'definition' => [
                'polygons' => [],
                'zip_codes' => array_map('strval', $zips),
            ],
        ];
    }

    private function defaultFormState(): array
    {
        return [
            'name' => null,
            'description' => null,
            'color' => Zone::DEFAULT_COLOR,
            'is_no_scan_zone' => false,
            'users' => [],
            'definition' => ['polygons' => [], 'zip_codes' => []],
        ];
    }
}

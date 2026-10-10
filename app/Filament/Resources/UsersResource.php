<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UsersResource\Pages;
use App\Models\KitSettings;
use App\Models\Role;
use App\Models\User;
use App\Support\Scout\ScoutUserProvisioner;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class UsersResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    public static function form(Schema $schema): Schema
    {
        $defaultUserRoleId = (int) Role::withoutGlobalScopes()
            ->whereNull('org_id')
            ->where('name', RoleEnum::USER->value)
            ->value('id');

        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required(),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->required()
                            ->email()
                            ->rules([
                                fn (?Model $record) => function (string $attribute, $value, \Closure $fail) use ($record): void {
                                    if (! $value) {
                                        return;
                                    }

                                    if ($message = User::scoutEmailCollisionMessage(
                                        (string) $value,
                                        $record?->id,
                                        scoutContext: ScoutUserProvisioner::canAccess(),
                                    )) {
                                        $fail($message);
                                    }
                                },
                            ]),

                        Forms\Components\CheckboxList::make('roles')
                            ->label('Roles')
                            ->relationship('roles')
                            ->pivotData([
                                'org_id' => auth()->user()->org_id,
                            ])
                            ->columns(4)
                            ->options(
                                Role::withoutGlobalScopes()
                                    ->whereNull('org_id')
                                    ->orWhere('org_id', auth()->user()->org_id)
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn ($role) => [
                                        $role->id => $role->name,
                                    ])
                            )
                            ->formatStateUsing(fn (mixed $state, ?Model $record) => $record ? $record->roles()->pluck('id')->toArray() : [])
                            // Re-add (not disableOptionWhen) keeps Default User checked while Scout is on: a disabled option is dropped from the valid `in:` set.
                            ->live()
                            ->afterStateUpdated(function (?array $state, Get $get, Set $set) use ($defaultUserRoleId): void {
                                if (! $get('scout_access') || ! $defaultUserRoleId) {
                                    return;
                                }

                                $roles = array_map('intval', (array) ($state ?? []));

                                if (! in_array($defaultUserRoleId, $roles, true)) {
                                    $roles[] = $defaultUserRoleId;
                                    $set('roles', $roles);
                                }
                            })
                            ->descriptions(fn (Get $get): array => $get('scout_access') && $defaultUserRoleId
                                ? [$defaultUserRoleId => 'Required for Scout users.']
                                : [])
                            ->minItems(1)
                            ->required(),

                        ...static::scoutFormFields($defaultUserRoleId),
                    ]),
            ]);
    }

    /**
     * The Scout controls that sit inline with the Roles group: a "Scout" checkbox
     * (a peer of the role checkboxes) and the Assigned Kit picker that appears — and
     * is required — once Scout is checked. Both are gated to Scout-enabled orgs'
     * admins. Enabling Scout force-checks the Default User role ($defaultUserRoleId);
     * the domain effect on save is handled by ScoutUserProvisioner.
     *
     * @return array<int, Component>
     */
    protected static function scoutFormFields(?int $defaultUserRoleId = null): array
    {
        return [
            Forms\Components\Checkbox::make('scout_access')
                ->label('Scout')
                ->helperText('Enable Clarity Scout driver-portal access for this user.')
                ->visible(fn (): bool => ScoutUserProvisioner::canAccess())
                ->live()
                ->afterStateHydrated(function (Forms\Components\Checkbox $component, ?Model $record): void {
                    if (! $record) {
                        $component->state(false);

                        return;
                    }

                    setPermissionsTeamId($record->org_id);
                    $component->state($record->hasPermissionTo('clarity-scout'));
                })
                ->afterStateUpdated(function (bool $state, Get $get, Set $set) use ($defaultUserRoleId): void {
                    if (! $state || ! $defaultUserRoleId) {
                        return;
                    }

                    $roles = array_map('intval', (array) ($get('roles') ?? []));

                    if (! in_array($defaultUserRoleId, $roles, true)) {
                        $roles[] = $defaultUserRoleId;
                        $set('roles', $roles);
                    }
                }),

            Forms\Components\Select::make('scout_kit_id')
                ->label('Assigned Kit')
                ->helperText('Choose the Kit this Scout user will use.')
                ->options(fn (?Model $record) => ScoutUserProvisioner::availableKitOptions(
                    auth()->user()->org_id,
                    $record ? KitSettings::where('user_id', $record->id)->value('kit_id') : null,
                ))
                ->searchable()
                ->visible(fn (Get $get): bool => ScoutUserProvisioner::canAccess() && (bool) $get('scout_access'))
                ->required(fn (Get $get): bool => (bool) $get('scout_access'))
                ->afterStateHydrated(function (Forms\Components\Select $component, ?Model $record): void {
                    $component->state($record ? KitSettings::where('user_id', $record->id)->value('kit_id') : null);
                }),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->sortable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Role(s)')
                    ->toggleable()
                    ->listWithLineBreaks()
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->timezone(auth()->user()->timezone)
                    ->dateTime(config('app.date_format'))
                    ->description(fn (Model $record): mixed => $record?->created_at?->setTimezone(auth()->user()->timezone)->diffForHumans())
                    ->sortable(),

                Tables\Columns\IconColumn::make('scout_access')
                    ->label('Scout Access')
                    ->boolean()
                    ->visible(fn (): bool => ScoutUserProvisioner::canAccess())
                    ->getStateUsing(fn (Model $record): bool => static::scoutEnabledUserIds($record->org_id)->contains($record->id)),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make()
                    ->label('Inactive Users')
                    ->placeholder('Without Inactive Users')
                    ->trueLabel('With Inactive Users')
                    ->falseLabel('Only Inactive Users'),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('New User')
                    ->icon('heroicon-o-plus'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('')
                    ->size('lg')
                    ->tooltip('Edit User'),

                DeleteAction::make()
                    ->label('')
                    ->size('lg')
                    ->tooltip('Delete User'),

                RestoreAction::make()
                    ->label('')
                    ->size('lg')
                    ->visible(fn (Model $record): bool => $record->trashed())
                    ->tooltip('Restore User'),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->where('org_id', auth()->user()->org_id)
                    ->withoutGlobalScope(SoftDeletingScope::class);
            })
            ->recordClasses(fn (Model $record): ?string => $record->deleted_at ? '!bg-red-200/20 dark:!bg-red-900/20' : null)
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUsers::route('/create'),
            'edit' => Pages\EditUsers::route('/{record}/edit'),
        ];
    }

    /**
     * Memoizes (per request) the ids of users in the given org who currently
     * hold the `clarity-scout` permission, so the `scout_access` table
     * column can do a single membership check per row instead of an N+1
     * setPermissionsTeamId()+hasPermissionTo() call per row.
     *
     * @return Collection<int, int>
     */
    protected static function scoutEnabledUserIds(int $orgId): Collection
    {
        return once(function () use ($orgId) {
            setPermissionsTeamId($orgId);

            return User::permission('clarity-scout')->pluck('id');
        });
    }
}

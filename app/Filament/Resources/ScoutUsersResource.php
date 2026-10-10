<?php

namespace App\Filament\Resources;

use App\Enums\FeatureEnum;
use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Enums\VehicleTypeEnum;
use App\Facades\Analytics;
use App\Filament\Concerns\LogsScoutActivity;
use App\Filament\Forms\Components\ZoneMapDisplay;
use App\Filament\Resources\ScoutUsersResource\Pages;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\User;
use App\Notifications\ScoutPasswordResetNotification;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

class ScoutUsersResource extends Resource
{
    use LogsScoutActivity;

    protected static ?string $model = Kit::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Scout Users';

    protected static ?string $pluralModelLabel = 'Scout Users';

    protected static ?string $modelLabel = 'Scout User';

    protected static ?string $slug = 'scout-users';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Scout';

    public static function getNavigationBadge(): ?string
    {
        return 'Pro';
    }

    public static function canAccess(): bool
    {
        return (Feature::active(FeatureEnum::CLARITY_SCOUT->slug()) || session()->has('admin_impersonator'))
            && auth()->user()->hasRole(RoleEnum::ADMIN->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Kit Name')
                            ->disabled(),

                        Forms\Components\TextInput::make('kit_mapping')
                            ->label('GL Number')
                            ->disabled(),

                        Forms\Components\Select::make('vehicle_type')
                            ->label('Vehicle Type')
                            ->options(VehicleTypeEnum::formOptions())
                            ->nullable()
                            ->placeholder('Unassigned')
                            ->dehydrated(true)
                            ->afterStateHydrated(function (Forms\Components\Select $component, ?Kit $record) {
                                $component->state($record?->settings?->vehicle_type?->value);
                            }),

                        Forms\Components\Toggle::make('create_new_user')
                            ->label('Create new user')
                            ->default(true)
                            ->live()
                            ->afterStateHydrated(function (Forms\Components\Toggle $component, ?Kit $record) {
                                $component->state(! ($record?->settings?->user_id));
                            }),

                        Forms\Components\Select::make('assigned_user_id')
                            ->label('Assigned User')
                            ->options(function (?Kit $record) {
                                $orgId = auth()->user()->org_id;

                                $assignedUserIds = KitSettings::query()
                                    ->whereNotNull('user_id')
                                    ->when($record, fn ($q) => $q->where('kit_id', '!=', $record->id))
                                    ->pluck('user_id');

                                return User::where('org_id', $orgId)
                                    ->whereNotIn('id', $assignedUserIds)
                                    ->get(['id', 'name', 'email'])
                                    ->mapWithKeys(fn (User $user) => [
                                        $user->id => $user->name ?: ($user->email ?: 'User #'.$user->id),
                                    ])
                                    ->all();
                            })
                            ->searchable()
                            ->live()
                            ->nullable()
                            ->placeholder('Unassigned')
                            ->visible(fn (Get $get) => ! $get('create_new_user'))
                            ->afterStateHydrated(function (Forms\Components\Select $component, ?Kit $record) {
                                $userId = $record?->settings?->user_id;

                                if (! $userId) {
                                    $component->state(null);

                                    return;
                                }

                                // Soft-deleted drivers are not assignable here — clear the
                                // select so existing_user_email is not required empty, and
                                // Restore on the list remains the path forward.
                                $active = User::query()->find($userId);
                                $component->state($active?->id);
                            })
                            ->afterStateUpdated(function ($state, Set $set, ?Kit $record) {
                                if (! $state) {
                                    $set('existing_user_email', null);
                                    $set('mobile_phone', $record?->text_phone);

                                    return;
                                }

                                $user = User::find($state);

                                $set('existing_user_email', $user?->email);
                                $set('mobile_phone', $user?->mobile_phone ?: $record?->text_phone);
                            }),

                        Forms\Components\TextInput::make('new_user_email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->rules([
                                fn () => function (string $attribute, $value, \Closure $fail): void {
                                    if (! $value) {
                                        return;
                                    }

                                    if ($message = User::scoutEmailCollisionMessage((string) $value)) {
                                        $fail($message);
                                    }
                                },
                            ])
                            ->visible(fn (Get $get) => $get('create_new_user')),

                        Forms\Components\TextInput::make('existing_user_email')
                            ->label('Email')
                            ->email()
                            ->required(fn (Get $get) => ! $get('create_new_user') && filled($get('assigned_user_id')))
                            ->visible(fn (Get $get) => ! $get('create_new_user') && filled($get('assigned_user_id')))
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Kit $record) {
                                $component->state($record?->settings?->user?->email);
                            })
                            ->rules([
                                fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get): void {
                                    if ($get('create_new_user') || ! $value) {
                                        return;
                                    }

                                    $assignedUserId = $get('assigned_user_id');
                                    $ignoreId = is_numeric($assignedUserId) ? (int) $assignedUserId : null;

                                    if ($message = User::scoutEmailCollisionMessage((string) $value, $ignoreId)) {
                                        $fail($message);
                                    }
                                },
                            ]),

                        Forms\Components\TextInput::make('mobile_phone')
                            ->label('Mobile Phone')
                            ->tel()
                            ->required(fn (Get $get) => (bool) $get('create_new_user') || filled($get('assigned_user_id')))
                            ->visible(fn (Get $get) => (bool) $get('create_new_user') || filled($get('assigned_user_id')))
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Kit $record) {
                                $user = $record?->settings?->user;

                                $component->state($user?->mobile_phone ?: $record?->text_phone);
                            }),

                        Forms\Components\Toggle::make('web_access_enabled')
                            ->label('Scout Access')
                            ->helperText('Enable Scout access for this user')
                            ->default(true)
                            ->afterStateHydrated(function (Forms\Components\Toggle $component, ?Kit $record, Get $get) {
                                if ($get('create_new_user')) {
                                    $component->state(true);

                                    return;
                                }

                                $user = $record?->settings?->user;
                                if ($user) {
                                    setPermissionsTeamId($user->org_id);
                                    $component->state($user->hasPermissionTo('clarity-scout'));
                                } else {
                                    $component->state(false);
                                }
                            }),
                    ]),

                Section::make('Assigned Zones')
                    ->visible(fn (?Kit $record) => $record?->settings?->user_id !== null)
                    ->schema([
                        ZoneMapDisplay::make('zones_map')->hiddenLabel(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Kit Name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('kit_mapping')
                    ->label('GL Number')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('settings.vehicle_type')
                    ->label('Vehicle Type')
                    ->badge()
                    ->formatStateUsing(fn (?VehicleTypeEnum $state) => $state?->title() ?? 'Unassigned')
                    ->color(fn (?VehicleTypeEnum $state) => $state ? 'info' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('assigned_user_name')
                    ->label('Assigned User')
                    ->getStateUsing(fn (Kit $record) => $record->assigned_user?->name ?? 'Unassigned')
                    ->badge()
                    ->color(fn (?string $state) => $state === 'Unassigned' ? 'gray' : 'success'),

                Tables\Columns\TextColumn::make('assigned_user_email')
                    ->label('Email')
                    ->getStateUsing(fn (Kit $record) => $record->assigned_user?->email ?? '—'),

                Tables\Columns\TextColumn::make('zones_count')
                    ->label('Assigned Zones')
                    ->getStateUsing(fn (Kit $record) => $record->assigned_user?->zones?->count() ?? 0)
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('assigned_user_phone')
                    ->label('Mobile Phone')
                    ->getStateUsing(fn (Kit $record) => $record->assigned_user?->mobile_phone ?? '—'),

                Tables\Columns\IconColumn::make('web_access')
                    ->label('Scout Access')
                    ->boolean()
                    ->getStateUsing(fn (Kit $record) => $record->assigned_user?->hasPermissionTo('clarity-scout') ?? false),
            ])
            ->filters([
                Tables\Filters\Filter::make('filters')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Select::make('assigned_user')
                            ->label('Assigned User')
                            ->placeholder('All')
                            ->options([
                                'assigned' => 'Has User',
                                'unassigned' => 'Unassigned',
                            ]),

                        Forms\Components\Select::make('web_access')
                            ->label('Scout Access')
                            ->placeholder('All')
                            ->options([
                                'enabled' => 'Enabled',
                                'disabled' => 'Disabled',
                            ]),

                        Forms\Components\Select::make('vehicle_type')
                            ->label('Vehicle Type')
                            ->placeholder('All')
                            ->options([
                                'unassigned' => 'Unassigned',
                                ...VehicleTypeEnum::formOptions(),
                            ]),
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];

                        if ($data['assigned_user'] ?? null) {
                            $indicators[] = 'User: '.($data['assigned_user'] === 'assigned' ? 'Has User' : 'Unassigned');
                        }

                        if ($data['web_access'] ?? null) {
                            $indicators[] = 'Scout Access: '.($data['web_access'] === 'enabled' ? 'Enabled' : 'Disabled');
                        }

                        if ($data['vehicle_type'] ?? null) {
                            $label = $data['vehicle_type'] === 'unassigned'
                                ? 'Unassigned'
                                : (VehicleTypeEnum::tryFrom($data['vehicle_type'])?->title() ?? $data['vehicle_type']);
                            $indicators[] = 'Vehicle Type: '.$label;
                        }

                        return $indicators !== [] ? $indicators : null;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['assigned_user'] ?? null, function (Builder $query, string $value) {
                                if ($value === 'assigned') {
                                    $query->whereHas('settings', fn ($s) => $s->whereNotNull('user_id'));
                                } else {
                                    $query->whereDoesntHave('settings', fn ($s) => $s->whereNotNull('user_id'));
                                }
                            })
                            ->when($data['web_access'] ?? null, function (Builder $query, string $value) {
                                $orgId = auth()->user()->org_id;
                                setPermissionsTeamId($orgId);

                                $userIdsWithAccess = User::where('org_id', $orgId)
                                    ->permission('clarity-scout')
                                    ->pluck('id');

                                $kitIdsWithAccess = KitSettings::whereIn('user_id', $userIdsWithAccess)->pluck('kit_id');

                                if ($value === 'enabled') {
                                    $query->whereIn('id', $kitIdsWithAccess);
                                } else {
                                    $query->whereNotIn('id', $kitIdsWithAccess);
                                }
                            })
                            ->when($data['vehicle_type'] ?? null, function (Builder $query, string $value) {
                                if ($value === 'unassigned') {
                                    $query->whereDoesntHave('settings', fn ($s) => $s->whereNotNull('vehicle_type'));
                                } else {
                                    $query->whereRelation('settings', 'vehicle_type', $value);
                                }
                            });
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('')
                    ->size('lg')
                    ->tooltip('Edit Scout User'),

                Action::make('force_login_portal')
                    ->label('')
                    ->size('lg')
                    ->tooltip('Login to Scout')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('warning')
                    ->extraAttributes(trackAttr(ScoutAnalyticsPage::ScoutUsers->value, ScoutAnalyticsEvent::ScoutUserForceLogin->value))
                    ->visible(fn (Kit $record) => session()->has('admin_impersonator')
                        && $record->assigned_user !== null
                        && static::trashedAssignedUser($record) === null)
                    ->url(fn (Kit $record) => route('clarity-scout.force-login', $record), shouldOpenInNewTab: true),

                Action::make('restore_scout_user')
                    ->label('')
                    ->size('lg')
                    ->tooltip('Restore deleted Scout user')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Restore deleted Scout user')
                    ->modalDescription(function (Kit $record): string {
                        $user = static::trashedAssignedUser($record);

                        return $user
                            ? 'Restore '.$user->name.' ('.$user->email.') and re-enable Scout access for this kit.'
                            : 'Restore this deleted Scout user.';
                    })
                    ->visible(fn (Kit $record): bool => static::trashedAssignedUser($record) !== null)
                    ->action(function (Kit $record): void {
                        $actingOrgId = auth()->user()->org_id;
                        $user = static::trashedAssignedUser($record);

                        if (! $user || $user->org_id !== $actingOrgId) {
                            Notification::make()
                                ->danger()
                                ->title('Could not restore')
                                ->body('This row is out of date. Refresh the page and try again.')
                                ->send();

                            return;
                        }

                        $shouldNotify = false;

                        DB::connection($user->getConnectionName())->transaction(function () use ($user, &$shouldNotify): void {
                            $user->restore();

                            setPermissionsTeamId($user->org_id);

                            if ($user->roles->isEmpty()) {
                                $user->assignRole(RoleEnum::USER->value);
                            }

                            if (! $user->hasPermissionTo('clarity-scout')) {
                                $user->givePermissionTo('clarity-scout');
                            }

                            $shouldNotify = ! $user->email_verified_at;
                        });

                        if ($shouldNotify) {
                            $user->sendScoutOnboardingNotification();
                        }

                        setPermissionsTeamId($actingOrgId);

                        Notification::make()
                            ->success()
                            ->title(
                                $shouldNotify
                                    ? 'Restored '.$user->name.', re-enabled Scout access, and resent the invite'
                                    : 'Restored '.$user->name.' and re-enabled Scout access'
                            )
                            ->send();

                        Analytics::track(
                            ScoutAnalyticsEvent::ScoutUserWebAccess->value,
                            auth()->user(),
                            ['action' => 'restore', 'kit_id' => $record->id, 'target_user_id' => $user->id],
                            ScoutAnalyticsPage::ScoutUsers->value,
                        );

                        activity('scout-user')
                            ->performedOn($user)
                            ->causedBy(auth()->user())
                            ->withProperties(static::scoutActivityProperties([
                                'action' => 'restore',
                                'kit_id' => $record->id,
                                'target_user_id' => $user->id,
                            ]))
                            ->log('Scout user restored');
                    }),

                ActionGroup::make([
                    Action::make('resend_onboarding')
                        ->label('Resend onboarding email')
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Resend Scout onboarding email')
                        ->modalDescription(fn (Kit $record) => 'Send a new set-password link to '.$record->assigned_user?->email.'. The previous link will stop working once they complete onboarding, or when it expires (3 days).')
                        ->visible(function (Kit $record): bool {
                            if (static::trashedAssignedUser($record) !== null) {
                                return false;
                            }

                            $user = $record->assigned_user;

                            if (! $user?->email || $user->email_verified_at) {
                                return false;
                            }

                            setPermissionsTeamId($user->org_id);

                            return $user->hasPermissionTo('clarity-scout');
                        })
                        ->action(function (Kit $record): void {
                            $user = $record->assigned_user;

                            if (! $user?->email || $user->email_verified_at) {
                                return;
                            }

                            setPermissionsTeamId($user->org_id);

                            if (! $user->hasPermissionTo('clarity-scout')) {
                                return;
                            }

                            $user->sendScoutOnboardingNotification();

                            Notification::make()
                                ->success()
                                ->title('Onboarding email resent to '.$user->email)
                                ->send();

                            Analytics::track(
                                ScoutAnalyticsEvent::ScoutUserResendOnboarding->value,
                                auth()->user(),
                                ['action' => 'resend', 'kit_id' => $record->id, 'target_user_id' => $user->id],
                                ScoutAnalyticsPage::ScoutUsers->value,
                            );

                            activity('scout-user')
                                ->performedOn($user)
                                ->causedBy(auth()->user())
                                ->withProperties(static::scoutActivityProperties([
                                    'action' => 'resend_onboarding',
                                    'kit_id' => $record->id,
                                    'target_user_id' => $user->id,
                                ]))
                                ->log('Scout onboarding email resent');
                        }),

                    Action::make('password_reset')
                        ->label('Send Password Reset')
                        ->icon('heroicon-o-key')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Send Scout password reset')
                        ->modalDescription(fn (Kit $record) => 'Send a password reset link to '.$record->assigned_user?->email.'.')
                        ->visible(function (Kit $record): bool {
                            $user = $record->assigned_user;

                            if (! $user?->email || ! $user->email_verified_at) {
                                return false;
                            }

                            setPermissionsTeamId($user->org_id);

                            return $user->hasPermissionTo('clarity-scout');
                        })
                        ->action(function (Kit $record): void {
                            $user = $record->assigned_user;

                            if (! $user?->email || ! $user->email_verified_at) {
                                return;
                            }

                            setPermissionsTeamId($user->org_id);

                            if (! $user->hasPermissionTo('clarity-scout')) {
                                return;
                            }

                            $user->notify(new ScoutPasswordResetNotification);

                            Notification::make()
                                ->success()
                                ->title('Password reset email sent to '.$user->email)
                                ->send();

                            Analytics::track(
                                ScoutAnalyticsEvent::ScoutUserPasswordReset->value,
                                auth()->user(),
                                ['action' => 'password_reset', 'kit_id' => $record->id, 'target_user_id' => $user->id],
                                ScoutAnalyticsPage::ScoutUsers->value,
                            );

                            activity('scout-user')
                                ->performedOn($user)
                                ->causedBy(auth()->user())
                                ->withProperties(static::scoutActivityProperties([
                                    'action' => 'password_reset',
                                    'kit_id' => $record->id,
                                    'target_user_id' => $user->id,
                                ]))
                                ->log('Scout password reset email sent');
                        }),

                    Action::make('toggle_web_access')
                        ->label(fn (Kit $record) => $record->assigned_user?->hasPermissionTo('clarity-scout') ? 'Disable Scout Access' : 'Enable Scout Access')
                        ->icon(fn (Kit $record) => $record->assigned_user?->hasPermissionTo('clarity-scout') ? 'heroicon-o-lock-closed' : 'heroicon-o-globe-alt')
                        ->color(fn (Kit $record) => $record->assigned_user?->hasPermissionTo('clarity-scout') ? 'danger' : 'success')
                        ->requiresConfirmation()
                        ->modalHeading(fn (Kit $record) => $record->assigned_user?->hasPermissionTo('clarity-scout') ? 'Disable Scout Access' : 'Enable Scout Access')
                        ->modalDescription(fn (Kit $record) => $record->assigned_user?->hasPermissionTo('clarity-scout')
                            ? 'This will revoke Scout access for '.$record->assigned_user->name.'.'
                            : 'This will grant Scout access to '.$record->assigned_user?->name.'.')
                        ->visible(fn (Kit $record) => $record->assigned_user !== null && static::trashedAssignedUser($record) === null)
                        ->action(function (Kit $record) {
                            $user = $record->assigned_user;

                            if (! $user) {
                                return;
                            }

                            if (! $user->email) {
                                Notification::make()
                                    ->danger()
                                    ->title('Email Required')
                                    ->body('User must have an email address to enable Scout access.')
                                    ->send();

                                return;
                            }

                            setPermissionsTeamId($user->org_id);

                            if ($user->hasPermissionTo('clarity-scout')) {
                                $user->revokePermissionTo('clarity-scout');
                                $accessAction = 'revoke';

                                Notification::make()
                                    ->success()
                                    ->title('Scout access disabled for '.$user->name)
                                    ->send();
                            } else {
                                // Assign default User role if no role assigned
                                if ($user->roles->isEmpty()) {
                                    $user->assignRole(RoleEnum::USER->value);
                                }

                                $user->givePermissionTo('clarity-scout');
                                $accessAction = 'grant';

                                if (! $user->email_verified_at) {
                                    $user->sendScoutOnboardingNotification();
                                }

                                Notification::make()
                                    ->success()
                                    ->title('Scout access enabled for '.$user->name)
                                    ->send();
                            }

                            Analytics::track(
                                ScoutAnalyticsEvent::ScoutUserWebAccess->value,
                                auth()->user(),
                                ['action' => $accessAction, 'kit_id' => $record->id],
                                ScoutAnalyticsPage::ScoutUsers->value,
                            );

                            activity('scout-user')
                                ->performedOn($user)
                                ->causedBy(auth()->user())
                                ->withProperties(static::scoutActivityProperties([
                                    'action' => $accessAction,
                                    'kit_id' => $record->id,
                                    'target_user_id' => $user->id,
                                ]))
                                ->log('Scout web access '.($accessAction === 'grant' ? 'granted' : 'revoked'));
                        }),
                ])
                    ->label('More actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('More actions'),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->where('org_id', auth()->user()->org_id)
                    ->with(['settings.user.roles.permissions', 'settings.user.permissions', 'settings.user.zones']);
            })
            // Same red wash as Users/Zones soft-delete rows and Scans `tr.invalid`.
            ->recordClasses(fn (Kit $record): ?string => static::trashedAssignedUser($record)
                ? '!bg-red-200/20 dark:!bg-red-900/20'
                : null)
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    /**
     * Soft-deleted user still linked to this kit via kit_settings (DEV-2593).
     * Does not alter Kit::assigned_user (active SoftDeletes scope stays intact).
     */
    public static function trashedAssignedUser(Kit $record): ?User
    {
        $userId = $record->settings?->user_id;

        if (! $userId) {
            return null;
        }

        // When settings.user is loaded and non-null, the driver is active (not trashed).
        if ($record->relationLoaded('settings')
            && $record->settings?->relationLoaded('user')
            && $record->settings->user !== null) {
            return null;
        }

        $user = User::withTrashed()
            ->where('org_id', auth()->user()->org_id)
            ->find($userId);

        return ($user && $user->trashed()) ? $user : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Scope the resource's record-resolution query so direct-URL access (e.g.
     * `/app/scout-users/<id>/edit`) can never reach another org's kit. The
     * table query is already org-scoped via `modifyQueryUsing`; without this,
     * the edit page used Kit::find($id) and would happily render a foreign
     * kit's form.
     *
     * Also excludes soft-deleted/deactivated kits (via {@see Kit::scopeExcludingDeleted})
     * so this admin list resolves the identical active-kit set the Scout org map
     * shows (ClarityScoutBirdsEyeController::orgScoutKits).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('org_id', auth()->user()->org_id)
            ->excludingDeleted();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScoutUsers::route('/'),
            'edit' => Pages\EditScoutUser::route('/{record}/edit'),
        ];
    }
}

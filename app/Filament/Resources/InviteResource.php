<?php

namespace App\Filament\Resources;

use App\Enums\FeatureEnum;
use App\Enums\InviteTypeEnum;
use App\Filament\Actions\InviteAction;
use App\Filament\Resources\InviteResource\Pages;
use App\Filament\Tables\Columns\DateTimeColumn;
use App\Models\Invite;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

class InviteResource extends Resource
{
    protected static ?string $model = Invite::class;

    protected static ?int $navigationSort = 998;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    public static function getNavigationBadge(): ?string
    {
        return 'Pro';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->description(fn (Model $record): ?string => InviteTypeEnum::tryFrom($record->type)?->description() ?? null),

                Tables\Columns\TextColumn::make('id')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(function (Model $record): string {
                        if ($record->expires_at && $record->expires_at < now()) {
                            return 'Expired';
                        }

                        if ($record->accepted_at) {
                            return 'Completed';
                        }

                        return 'Pending';
                    })
                    ->color(function (Model $record): string {
                        if ($record->expires_at && $record->expires_at < now()) {
                            return 'danger';
                        }

                        if ($record->accepted_at) {
                            return 'success';
                        }

                        return 'warning';
                    }),

                Tables\Columns\TextColumn::make('meta.code')
                    ->label('Code')
                    ->visible(function (HasTable $livewire, ?Model $record): bool {
                        $direction = $livewire->getTable()->hasDeferredFilters()
                            ? data_get($livewire, 'tableDeferredFilters.filters.direction', 'received')
                            : data_get($livewire, 'tableFilters.filters.direction', 'received');

                        return \array_key_exists('code', $record?->meta ?? []) && $direction === 'sent';
                    }),

                Tables\Columns\TextColumn::make('user_name')
                    ->label('Send By')
                    ->description(fn (Model $record): ?string => $record->user_name !== $record->user_email ? $record->user_email : null),

                DateTimeColumn::make('created_at')
                    ->label('Created')
                    ->toggleable(),

                DateTimeColumn::make('expires_at')
                    ->label('Expires')
                    ->toggleable(),

                DateTimeColumn::make('accepted_at')
                    ->label('Accepted')
                    ->default('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->toolbarActions([
                InviteAction::make(),
            ])
            ->recordActions([
                Action::make('delete')
                    ->hiddenLabel()
                    ->icon('heroicon-o-trash')
                    ->tooltip('Delete Invite')
                    ->color('danger')
                    ->extraAttributes(trackAttr('invites', 'delete-button'))
                    ->visible(fn (?Model $record): bool => $record?->user_id === auth()->id())
                    ->action(fn (Model $record) => $record->delete())
                    ->requiresConfirmation()
                    ->modalHeading('Delete Invite')
                    ->modalSubmitActionLabel('Delete Invite')
                    ->modalDescription('Are you sure you\'d like to delete this sent Invite? This cannot be undone and you will need to send a new Invite.'),

                Action::make('accept')
                    ->hiddenLabel()
                    ->icon('heroicon-o-check')
                    ->tooltip('Accept Invite')
                    ->color('success')
                    ->size('md')
                    ->button()
                    ->extraAttributes(trackAttr('invites', 'accept-button'))
                    ->visible(function (Model $record): bool {
                        return $record->invitable_type === 'user'
                            && $record->invitable_id === auth()->id()
                            && ($record->expires_at === null || $record->expires_at > now())
                            && ! $record->accepted_at;
                    })
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Invite Code')
                            ->helperText('Enter the 6-digit Code you received with this Invite to accept.')
                            ->visible(fn (Model $record): bool => \array_key_exists('code', $record->meta ?? []))
                            ->rules(fn (Model $record): array => [
                                Rule::requiredIf(\array_key_exists('code', $record->meta ?? [])),
                                Rule::in(
                                    \array_key_exists('code', $record->meta ?? [])
                                        ? [$record->meta['code']]
                                        : []
                                ),
                            ])
                            ->validationMessages([
                                'in' => 'The provided Code is invalid, please try again.',
                            ]),
                    ])
                    ->action(fn (Model $record) => $record->accept()),

                Action::make('decline')
                    ->hiddenLabel()
                    ->icon('heroicon-o-x-mark')
                    ->tooltip('Decline Invite')
                    ->color('danger')
                    ->size('md')
                    ->button()
                    ->extraAttributes(trackAttr('invites', 'decline-button'))
                    ->visible(function (Model $record): bool {
                        return $record->invitable_type === 'user'
                            && $record->invitable_id === auth()->id()
                            && ($record->expires_at === null || $record->expires_at > now())
                            && ! $record->accepted_at;
                    })
                    ->action(fn (Model $record) => $record->decline())
                    ->requiresConfirmation()
                    ->modalHeading('Decline Invite')
                    ->modalSubmitActionLabel('Decline Invite')
                    ->modalDescription('Are you sure you\'d like to decline this Invite? This cannot be undone and you will need a new Invite to be sent again.'),
            ])
            ->filters([
                Filter::make('filters')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Invite Type')
                            ->columnSpanFull()
                            ->visible(fn (): bool => auth()->user()->hasRole(RoleEnum::ADMIN->value))
                            ->options(InviteTypeEnum::formOptions()),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->columnSpanFull()
                            ->selectablePlaceholder(false)
                            ->default('all')
                            ->options([
                                'all' => 'All',
                                'pending' => 'Pending',
                                'completed' => 'Completed',
                                'expired' => 'Expired',
                            ]),

                        Forms\Components\ToggleButtons::make('direction')
                            ->hiddenLabel()
                            ->inline()
                            ->columnSpanFull()
                            ->visible(fn (): bool => auth()->user()->hasRole(RoleEnum::ADMIN->value))
                            ->default(fn (): string => auth()->user()->hasRole(RoleEnum::CLIENT->value) ? 'received' : 'sent')
                            ->options([
                                'sent' => 'Sent Invites',
                                'received' => 'Received Invites',
                            ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['status'],
                            fn (Builder $query, $status): Builder => $query
                                ->when(
                                    $status === 'pending',
                                    fn (Builder $query): Builder => $query
                                        ->whereNull('accepted_at')
                                        ->where(fn (Builder $query) => $query
                                            ->whereNull('expires_at')
                                            ->orWhere('expires_at', '>', now())
                                        ),
                                )
                                ->when(
                                    $status === 'completed',
                                    fn (Builder $query): Builder => $query->whereNotNull('accepted_at'),
                                )
                                ->when(
                                    $status === 'expired',
                                    fn (Builder $query): Builder => $query->where('expires_at', '<', now()),
                                )
                        )
                        ->when(
                            $data['type'],
                            fn (Builder $query, $type): Builder => $query->where('type', $type)
                        )
                        ->when(
                            $data['direction'],
                            fn (Builder $query, $direction): Builder => $query
                                ->when(
                                    $direction === 'sent',
                                    fn (Builder $query): Builder => $query->where('user_id', auth()->id()),
                                )
                                ->when(
                                    $direction === 'received',
                                    fn (Builder $query): Builder => $query
                                        ->where('invitable_type', 'user')
                                        ->where('invitable_id', auth()->id())
                                )
                        )
                    )
                    ->indicator(false),
            ])
            ->query(
                Invite::query()
                    ->withoutGlobalScope('expires')
                    ->join('users', 'users.id', '=', 'invites.user_id')
                    ->where(function (Builder $query) {
                        $query
                            ->where('invites.user_id', auth()->id())
                            ->orWhere(fn (Builder $query) => $query
                                ->where('invites.invitable_type', 'user')
                                ->where('invites.invitable_id', auth()->id())
                            );
                    })
                    ->select([
                        'invites.id as id',
                        'invites.type as type',
                        'invites.meta as meta',
                        'invites.user_id as user_id',
                        'invites.invitable_type as invitable_type',
                        'invites.invitable_id as invitable_id',
                        'users.name as user_name',
                        'users.email as user_email',
                        'invites.accepted_at as accepted_at',
                        'invites.expires_at as expires_at',
                        'invites.created_at as created_at',
                    ])
            )
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvites::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole([RoleEnum::ADMIN->value, RoleEnum::CLIENT->value]);
    }

    public static function canView(Model $record): bool
    {
        return (session()->has('admin_impersonator') || Feature::active(FeatureEnum::INVITES->slug()))
            && auth()->user()->hasRole(RoleEnum::ADMIN->value)
            && (
                $record->user_id === auth()->id()
                || ($record->invitable_type === 'user' && $record->invitable_id === auth()->id())
            );
    }

    public static function canAccess(): bool
    {
        if (config('app.env') === 'production') {
            return Feature::active(FeatureEnum::INVITES->slug()) || session()->has('admin_impersonator');
        }

        return static::canViewAny();
    }
}

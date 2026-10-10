<?php

namespace App\Filament\Widgets\AlertSettings;

use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Models\AlertSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class SubscribedAlertsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = null;

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Subscribed Alerts From Other Users')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Notification')
                    ->formatStateUsing(function (Model $record) {
                        $state = [];

                        if ($record->routeNotificationForSms()->contains(auth()->user()->formatted_mobile_phone)) {
                            $state[] = 'SMS';
                        }

                        if ($record->routeNotificationForMail()->contains(auth()->user()->email)) {
                            $state[] = 'Email';
                        }

                        return \implode(', ', $state);
                    })
                    ->extraAttributes(function (Model $record) {
                        $state = [];

                        if ($record->routeNotificationForSms()->contains(auth()->user()->formatted_mobile_phone)) {
                            $state[] = 'SMS';
                        }

                        if ($record->routeNotificationForMail()->contains(auth()->user()->email)) {
                            $state[] = 'Email';
                        }

                        return [
                            'data-alert' => $record->id.':'.\implode(', ', $state),
                        ];
                    }),

                Tables\Columns\TextColumn::make('settings.types')
                    ->label('Alert Type(s)')
                    ->badge()
                    ->listWithLineBreaks()
                    ->formatStateUsing(fn ($state) => HitTypeEnum::tryFrom($state)?->title() ?? Str::headline($state)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created At')
                    ->timezone(auth()->user()->timezone)
                    ->dateTime(config('app.date_format'))
                    ->description(fn (Model $record): mixed => $record?->created_at?->setTimezone(auth()->user()->timezone)->diffForHumans())
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated At')
                    ->timezone(auth()->user()->timezone)
                    ->dateTime(config('app.date_format'))
                    ->description(fn (Model $record): mixed => $record?->updated_at?->setTimezone(auth()->user()->timezone)->diffForHumans())
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('unsubscribe')
                    ->icon('heroicon-o-bell-slash')
                    ->label('')
                    ->size('lg')
                    ->tooltip('Unsubscribe from this Alert')
                    ->modalHeading('Unsubscribe from this Alert')
                    ->modalDescription('Are you sure you want to unsubscribe from this alert?')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->removeEmail(auth()->user()->email);

                        activity('Alert Settings Unsubscribe')
                            ->performedOn($record)
                            ->withProperties([
                                'email' => auth()->user()->email,
                            ])
                            ->log('Unsubscribed from alert');

                        Notification::make()
                            ->title('You have been unsubscribed from this alert.')
                            ->success()
                            ->send();
                    }),
            ])
            ->query(
                AlertSetting::query()
                    ->where('org_id', auth()->user()->org_id)
                    ->where(fn ($subQuery) => $subQuery
                        ->where('alertable_type', 'user')
                        ->where('alertable_id', '!=', auth()->id())
                    )
                    ->where(fn ($subQuery) => $subQuery
                        ->whereJsonContains('settings->notifications->emails', auth()->user()->email)
                        ->orWhereJsonContains('settings->notifications->sms', auth()->user()->formatted_mobile_phone)
                    )
            )
            ->paginated(false)
            ->paginationPageOptions(['all'])
            ->emptyStateHeading('No Other Alerts');
    }

    public function getTableRecordKey(Model|array|null $record = null): string
    {
        return $record?->id ?? uniqid();
    }

    public static function canView(): bool
    {
        return Feature::active(FeatureEnum::ALERT_SETTINGS->slug()) || session()->has('admin_impersonator');
    }
}

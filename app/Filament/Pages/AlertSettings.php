<?php

namespace App\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Facades\Analytics;
use App\Filament\Concerns\HasProFeature;
use App\Filament\Forms\AlertSettingsForm;
use App\Filament\Widgets\AlertSettings\SubscribedAlertsWidget;
use App\Models\AlertSetting;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AlertSettings extends Page implements HasTable
{
    use HasProFeature;
    use InteractsWithTable;

    protected static ?int $navigationSort = 999;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected string $view = 'filament.pages.alert-settings';

    public function mount(): void
    {
        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'alert-settings');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public static function getNavigationBadge(): ?string
    {
        return 'Pro';
    }

    protected function getFooterWidgets(): array
    {
        return [
            SubscribedAlertsWidget::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('My Alert Settings')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->sortable(),

                Tables\Columns\TextColumn::make('settings.types')
                    ->label('Alert Type(s)')
                    ->badge()
                    ->listWithLineBreaks()
                    ->formatStateUsing(fn ($state) => HitTypeEnum::tryFrom($state)?->title() ?? Str::headline($state)),

                Tables\Columns\TextColumn::make('settings')
                    ->label('Settings')
                    ->formatStateUsing(fn ($state) => collect($state->notifications)
                        ->map(fn ($data, $key) => Str::headline($key).': '.count($data))
                        ->implode(' | ')
                    ),

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
                EditAction::make()
                    ->label('')
                    ->size('lg')
                    ->tooltip('Edit Alert Setting')
                    ->modalWidth('7xl')
                    ->schema(AlertSettingsForm::form())
                    ->fillForm(fn ($record) => AlertSettingsForm::fill($record))
                    ->action(fn ($record, $data) => AlertSettingsForm::save(alertable: auth()->user(), data: $data, alertSetting: $record)),

                DeleteAction::make()
                    ->label('')
                    ->size('lg')
                    ->tooltip('Delete Alert Setting')
                    ->requiresConfirmation(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('New Alert Setting')
                    ->icon('heroicon-o-plus')
                    ->modalWidth('7xl')
                    ->schema(AlertSettingsForm::form())
                    ->action(fn ($data) => AlertSettingsForm::save(alertable: auth()->user(), data: $data)),
            ])
            ->query(
                AlertSetting::query()
                    ->where('alertable_id', auth()->id())
                    ->where('alertable_type', 'user')
            );
    }

    public function getFeatureName(): string|array
    {
        return FeatureEnum::ALERT_SETTINGS->slug();
    }

    public static function canAccess(): bool
    {
        return true;
    }
}

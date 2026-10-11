<?php

namespace App\Filament\Forms;

use App\Dto\AlertSettingSettingsDto;
use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Models\AlertSetting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class AlertSettingsForm
{
    use InteractsWithForms;

    public static function form(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),

            Section::make('Alert Filters')
                ->icon('heroicon-o-adjustments-vertical')
                ->description('Select the criteria for which you want to receive alerts.')
                ->compact()
                ->aside()
                ->schema([
                    Forms\Components\ToggleButtons::make('types')
                        ->label('Alert Type')
                        ->required()
                        ->inline()
                        ->required()
                        ->multiple()
                        ->helperText('Select the type(s) of Hits you want to create alerts for.')
                        ->options(
                            collect(HitTypeEnum::formOptions())
                                ->filter(fn ($type, $value) => $value === HitTypeEnum::Missed_Opportunity->value ? auth()->user()->isProEnabled(FeatureEnum::MISSED_OPPORTUNITY) : true)
                                ->toArray()
                        ),

                    Forms\Components\Select::make('clients')
                        ->label('Client(s)')
                        ->multiple()
                        ->options(fn () => auth()->user()->org->getHotlistClients())
                        ->searchable()
                        ->optionsLimit(9999)
                        ->noSearchResultsMessage('No clients found.')
                        ->helperText('Select specific clients to receive alerts for.')
                        ->hintActions([
                            Action::make('selectAll')
                                ->icon('heroicon-o-rectangle-stack')
                                ->visible(fn ($state): bool => ! isset($state[0]))
                                ->action(fn (Set $set, $component): mixed => $set('clients', \array_keys($component->getOptions()))),

                            Action::make('removeAll')
                                ->icon('heroicon-m-x-mark')
                                ->color('warning')
                                ->visible(fn ($state): bool => isset($state[0]))
                                ->action(fn (Set $set): mixed => $set('clients', [])),
                        ]),
                ]),

            Section::make('Notify Me By')
                ->icon('heroicon-o-bell-alert')
                ->description('Select how you would like to be notified for this alert.')
                ->compact()
                ->aside()
                ->schema([
                    Forms\Components\Toggle::make('personal_email')
                        ->label('Email')
                        ->live()
                        ->offIcon('heroicon-o-x-mark')
                        ->onIcon('heroicon-o-check')
                        ->onColor('success')
                        ->hintColor(fn (Get $get) => $get('personal_email') ? 'success' : 'gray')
                        ->hint(auth()->user()->email),

                    Forms\Components\Toggle::make('personal_phone')
                        ->label('Text Message')
                        ->live()
                        ->offIcon('heroicon-o-x-mark')
                        ->onIcon('heroicon-o-check')
                        ->onColor('success')
                        ->hintColor(fn (Get $get) => $get('personal_phone') ? 'success' : 'gray')
                        ->hint(auth()->user()->mobile_phone ?? null),

                    Forms\Components\TextInput::make('mobile_phone')
                        ->label('Mobile Phone')
                        ->placeholder('Enter your mobile phone number')
                        ->default(fn () => auth()->user()->mobile_phone ?? null)
                        ->tel()
                        ->visible(fn (Get $get) => $get('personal_phone'))
                        ->required(fn (Get $get) => $get('personal_phone'))
                        ->disabled(fn (Get $get) => ! $get('personal_phone')),
                ]),

            Section::make('Email Notifications')
                ->icon('heroicon-o-envelope')
                ->description('Add additional email addresses to receive notifications.')
                ->compact()
                ->aside()
                ->schema([
                    Forms\Components\Select::make('email_users')
                        ->label('Internal Users')
                        ->multiple()
                        ->options(
                            User::query()
                                ->where('id', '!=', auth()->user()->id)
                                ->where('org_id', auth()->user()->org_id)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn ($user) => [
                                    $user->email => ($user->getFilamentName() !== $user->email) ? ($user->getFilamentName().' ('.$user->email.')') : $user->email,
                                ])
                                ->toArray()
                        )
                        ->live()
                        ->searchable()
                        ->dehydrated(false)
                        ->optionsLimit(9999)
                        ->validatedWhenNotDehydrated(false)
                        ->disableOptionWhen(fn (string $value): bool => $value === auth()->user()->email)
                        ->hintActions([
                            Action::make('selectAll')
                                ->icon('heroicon-o-rectangle-stack')
                                ->visible(fn ($state, $component): bool => ! isset($state[0]) && \count($component->getOptions()) > 0)
                                ->action(fn (Set $set, $component): mixed => $set('email_users', \array_keys($component->getOptions()))),

                            Action::make('removeAll')
                                ->icon('heroicon-m-x-mark')
                                ->color('warning')
                                ->visible(fn ($state): bool => isset($state[0]))
                                ->action(fn (Set $set): mixed => $set('email_users', [])),
                        ]),

                    Forms\Components\TagsInput::make('external_email_users')
                        ->label('External Emails')
                        ->placeholder('Enter emails then press "Tab"')
                        ->rules(['array'])
                        ->nestedRecursiveRules(['email:rfc,dns']),
                ]),

            Section::make('Text Message/SMS')
                ->icon('heroicon-o-device-phone-mobile')
                ->description('Add additional phone numbers to receive notifications.')
                ->compact()
                ->aside()
                ->schema([
                    Forms\Components\Select::make('sms_users')
                        ->label('Internal Users')
                        ->multiple()
                        ->options(
                            User::query()
                                ->where('id', '!=', auth()->user()->id)
                                ->where('org_id', auth()->user()->org_id)
                                ->where(fn ($query) => $query
                                    ->whereNotNull('mobile_phone')
                                    ->orWhere('mobile_phone', '!=', '')
                                )
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn ($user) => [
                                    $user->formatted_mobile_phone => $user->getFilamentName().' ('.$user->mobile_phone.')',
                                ])
                                ->toArray()
                        )
                        ->live()
                        ->searchable()
                        ->dehydrated(false)
                        ->optionsLimit(9999)
                        ->validatedWhenNotDehydrated(false)
                        ->noSearchResultsMessage('No users with a phone number found.')
                        ->disableOptionWhen(fn (string $value): bool => $value === auth()->user()->mobile_phone)
                        ->hintActions([
                            Action::make('selectAll')
                                ->icon('heroicon-o-rectangle-stack')
                                ->visible(fn ($state, $component): bool => ! isset($state[0]) && \count($component->getOptions()) > 0)
                                ->action(fn (Set $set, $component): mixed => $set('sms_users', \array_keys($component->getOptions()))),

                            Action::make('removeAll')
                                ->icon('heroicon-m-x-mark')
                                ->color('warning')
                                ->visible(fn ($state): bool => isset($state[0]))
                                ->action(fn (Set $set): mixed => $set('sms_users', [])),
                        ]),

                    Forms\Components\TagsInput::make('external_sms_users')
                        ->label('External Phone Numbers')
                        ->placeholder('Enter phone numbers then press "Tab"'),
                ]),

            Section::make('Slack Webhooks')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->description(new HtmlString('See the <a href="https://api.slack.com/messaging/webhooks" class="underline" target="_blank">Slack Webhook documentation</a> for more information on how to create a webhook'))
                ->compact()
                ->aside()
                ->schema([
                    Forms\Components\TagsInput::make('slack_webhooks')
                        ->label('Slack Webhooks')
                        ->placeholder('Enter a valid Slack Webhook URL then press "Tab"')
                        ->helperText('All Webhook URLs should start with "https://hooks.slack.com/services/"'),
                ]),

            Section::make('Webhook')
                ->icon('heroicon-o-globe-alt')
                ->description('Receive a real-time HTTP POST to your own endpoint whenever a matching hit fires.')
                ->compact()
                ->aside()
                ->visible(fn () => Feature::active(FeatureEnum::HIT_WEBHOOK->slug()))
                ->schema([
                    Forms\Components\TextInput::make('webhook_url')
                        ->label('Webhook URL')
                        ->url()
                        ->maxLength(2048)
                        ->placeholder('https://example.com/hooks/clarity')
                        ->helperText('Each POST includes an "X-Webhook-Secret" header so you can verify it came from Clarity.'),
                ]),
        ];
    }

    public static function fill(AlertSetting $alertSetting): array
    {
        $internalEmailUsers = User::query()
            ->where('id', '!=', auth()->user()->id)
            ->where('org_id', auth()->user()->org_id)
            ->pluck('email')
            ->intersect($alertSetting->routeNotificationForMail())
            ->values()
            ->toArray();

        $internalSmsUsers = User::query()
            ->where('id', '!=', auth()->user()->id)
            ->where('org_id', auth()->user()->org_id)
            ->where(fn ($query) => $query
                ->whereNotNull('mobile_phone')
                ->orWhere('mobile_phone', '!=', '')
            )
            ->pluck('mobile_phone')
            ->map(fn ($phone) => \trim(\preg_replace('/\D/', '', $phone)))
            ->intersect($alertSetting->routeNotificationForSms())
            ->values()
            ->toArray();

        return [
            'name' => $alertSetting->name,
            'types' => $alertSetting->settings->types,
            'personal_email' => $alertSetting->routeNotificationForMail()->contains(auth()->user()->email) ?? false,
            'personal_phone' => auth()->user()->mobile_phone && $alertSetting->routeNotificationForSms()->contains(auth()->user()->formatted_mobile_phone),
            'mobile_phone' => auth()->user()->mobile_phone ?? null,
            'clients' => $alertSetting->settings->clients,
            'slack_webhooks' => $alertSetting->routeNotificationForSlack()->toArray(),
            'webhook_url' => $alertSetting->settings->webhook_url,
            'email_users' => User::query()
                ->where('id', '!=', auth()->user()->id)
                ->where('org_id', auth()->user()->org_id)
                ->pluck('email')
                ->intersect($alertSetting->routeNotificationForMail())
                ->values()
                ->toArray(),
            'external_email_users' => $alertSetting->routeNotificationForMail()
                ->filter(fn ($email) => $email !== auth()->user()->email && ! \in_array($email, $internalEmailUsers))
                ->values()
                ->toArray(),
            'sms_users' => User::query()
                ->where('id', '!=', auth()->user()->id)
                ->where('org_id', auth()->user()->org_id)
                ->where(fn ($query) => $query
                    ->whereNotNull('mobile_phone')
                    ->orWhere('mobile_phone', '!=', '')
                )
                ->pluck('mobile_phone')
                ->map(fn ($phone) => \trim(\preg_replace('/\D/', '', $phone)))
                ->intersect($alertSetting->routeNotificationForSms())
                ->values()
                ->toArray(),
            'external_sms_users' => $alertSetting->routeNotificationForSms()
                ->filter(fn ($phone) => $phone !== auth()->user()->formatted_mobile_phone && ! \in_array($phone, $internalSmsUsers))
                ->values()
                ->toArray(),
        ];
    }

    public static function save(Model $alertable, array $data, ?AlertSetting $alertSetting = null): void
    {
        if (empty($alertSetting)) {
            $alertSetting = $alertable->alertable()->newModelInstance();
            $alertSetting->alertable_type = $alertable->getMorphClass();
            $alertSetting->alertable_id = $alertable->id;
        }

        if ($alertable->getMorphClass() === 'user') {
            $alertSetting->org_id = $alertable->org_id;

            // Update user's mobile phone.
            $mobilePhone = data_get($data, key: 'mobile_phone', default: false);
            if ($mobilePhone && auth()->user()->mobile_phone !== $mobilePhone) {
                auth()->user()->mobile_phone = data_get($data, 'mobile_phone');
                auth()->user()->save();
            }
        }

        $alertSetting->name = data_get($data, 'name');

        $alertSetting->settings = AlertSettingSettingsDto::fromArray([
            'types' => data_get($data, 'types'),
            'clients' => data_get($data, 'clients'),
            'webhook_url' => self::formatWebhook($data),
            'notifications' => [
                'sms' => self::formatSms($data),
                'emails' => self::formatEmails($data),
                'slack' => self::formatSlack($data),
            ],
        ]);

        if ($alertSetting->settings->webhook_url !== '' && empty($alertSetting->webhook_secret)) {
            $alertSetting->webhook_secret = Str::random(40);
        }

        $alertSetting->save();
    }

    public static function formatSms($data): array
    {
        return collect($data['sms_users'] ?? [])
            ->merge($data['external_sms_users'] ?? [])
            ->when(
                ($data['personal_phone'] ?? false) === true,
                // Add current user's phone
                fn (Collection $collection) => $collection->push(auth()->user()->mobile_phone),
                // Remove current user's phone if not selected
                fn (Collection $collection) => $collection->filter(fn ($phone) => \trim(\preg_replace('/\D/', '', (string) $phone)) !== auth()->user()->formatted_mobile_phone),
            )
            ->map(fn ($phone) => \trim(\preg_replace('/\D/', '', (string) $phone)))
            ->unique()
            ->values()
            ->toArray();
    }

    public static function formatEmails($data): array
    {
        return collect($data['email_users'] ?? [])
            ->merge($data['external_email_users'] ?? [])
            ->when(
                ($data['personal_email'] ?? false) === true,
                // Add current user's email
                fn (Collection $collection) => $collection->push(auth()->user()->email),
                // Remove current user's email if not selected
                fn (Collection $collection) => $collection->filter(fn ($email) => $email !== auth()->user()->email),
            )
            ->unique()
            ->values()
            ->toArray();
    }

    public static function formatSlack($data): array
    {
        return collect($data['slack_webhooks'] ?? [])
            ->filter(fn ($webhook) => \str_starts_with($webhook, 'https://hooks.slack.com/services/'))
            ->unique()
            ->values()
            ->toArray();
    }

    public static function formatWebhook($data): string
    {
        return \trim((string) ($data['webhook_url'] ?? ''));
    }
}

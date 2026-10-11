<?php

namespace App\Filament\Pages;

use App\Facades\Analytics;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class Profile extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Profile';

    protected static string|null|\UnitEnum $navigationGroup = 'Account';

    protected string $view = 'filament.pages.profile';

    protected static ?int $navigationSort = 999;

    public ?array $data = [];

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function mount(): void
    {
        $this->form->fill([
            'name' => auth()->user()->name,
            'mobile_phone' => auth()->user()->formatted_mobile_phone,
        ]);

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'profile');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Subscription')
                    ->description('Your current plan information.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Placeholder::make('plan_info')
                            ->hiddenLabel()
                            ->content(function () {
                                $tierLabel = auth()->user()->org->proTier()->label();
                                $isAdmin = auth()->user()->hasRole(RoleEnum::ADMIN->value);

                                $text = "You are currently on the <strong>{$tierLabel}</strong> plan.";

                                if ($isAdmin) {
                                    $url = Subscription::getUrl();
                                    $text .= " <a href=\"{$url}\" class=\"text-primary-600 hover:underline\">Manage your plan here.</a>";
                                }

                                return new HtmlString($text);
                            }),
                    ]),

                Section::make('Profile Information')
                    ->description('Update your account profile information.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('mobile_phone')
                            ->label('Mobile Phone')
                            ->tel(),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Changes')
                ->action('save')
                ->color('primary'),
        ];
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            $user = auth()->user();

            // Update basic profile information
            $user->update([
                'name' => $data['name'],
                'mobile_phone' => \trim(\preg_replace('/\D/', '', $data['mobile_phone'])),
            ]);

            Notification::make()
                ->title('Profile updated successfully')
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('An error occurred while updating your profile.')
                ->danger()
                ->send();
        }
    }
}

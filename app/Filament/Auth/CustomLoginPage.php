<?php

namespace App\Filament\Auth;

use App\Filament\Client\Resources\ClientSearchScansResource;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class CustomLoginPage extends Login
{
    public bool $otp_sent = false;

    public function mount(): void
    {
        if (auth()->check()) {
            if (auth()->user()->canAccessPanel(Filament::getPanel('client'))) {
                redirect()->intended(ClientSearchScansResource::getUrl(panel: 'client'));
            } else {
                redirect()->to(Dashboard::getUrl(panel: 'app'));
            }

            return;
        }

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getOtpFormComponent(),
            ]);
    }

    public function getOtpFormComponent(): TextInput
    {
        return TextInput::make('otp')
            ->label('OTP')
            ->hint(fn (): string => $this->otp_sent ? 'Please enter the One Time Password sent to your email' : '')
            ->hidden(fn (): bool => ! $this->otp_sent)
            ->placeholder('Enter OTP')
            ->autofocus()
            ->required();
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        $otp_data = null;
        $bypassed_non_production = false;

        if (! app()->isProduction()) {
            $user = $this->resolveEligibleUser($data['email']);

            if (! $user) {
                throw ValidationException::withMessages([
                    'data.email' => 'Account Not Found',
                ]);
            }

            Filament::auth()->login($user);
            $bypassed_non_production = true;
        } elseif (! $this->otp_sent) {
            $this->sendOtp();

            return null;
        } else {
            $user = User::where('email', $data['email'])->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'data.email' => 'Email is invalid',
                ]);
            }

            $otp_data = OneTimePassword::get($user);

            if (! empty($otp_data) && (string) $otp_data['otp'] === (string) $data['otp']) {
                OneTimePassword::forget($user);
                Filament::auth()->login($user);
            } else {
                throw ValidationException::withMessages([
                    'data.otp' => 'Invalid One Time Password',
                ]);
            }
        }

        $user = Filament::auth()->user();

        if (
            ($user instanceof FilamentUser) &&
            (! $this->canAccessAnyPanel($user))
        ) {
            Filament::auth()->logout();

            $this->throwFailureValidationException();
        }

        session()->regenerate();

        activity('Login')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'otp' => $bypassed_non_production ? 'BYPASSED_NON_PROD' : ($otp_data['otp'] ?? 'N/A'),
                'email' => $data['email'],
            ])
            ->log('User logged in');

        return app(LoginResponse::class);
    }

    public function sendOtp(): void
    {
        $user = $this->resolveEligibleUser($this->data['email']);

        if ($user) {
            Notification::make()
                ->success()
                ->body('One time password sent')
                ->title('Sent')
                ->send();

            OneTimePassword::send($user);

            $this->otp_sent = true;

            return;
        }

        throw ValidationException::withMessages([
            'data.email' => 'Account Not Found',
        ]);
    }

    public function getFormActions(): array
    {
        return [
            Action::make('authenticate')
                ->label(fn (): string => $this->otp_sent ? 'Login' : 'Send OTP')
                ->submit('authenticate'),

            Action::make('resend_otp')
                ->label('Resend OTP')
                ->outlined()
                ->link()
                ->hidden(fn (): bool => ! $this->otp_sent)
                ->action(fn () => $this->sendOtp()),
        ];
    }

    private function canAccessAnyPanel(FilamentUser $user): bool
    {
        return $user->canAccessPanel(Filament::getPanel('client'))
            || $user->canAccessPanel(Filament::getPanel('app'));
    }

    /**
     * Resolve the user for the given email, if they exist and are eligible
     * to access a panel. Used both by the OTP flow and the non-production
     * OTP bypass so the eligibility check never drifts between the two.
     */
    private function resolveEligibleUser(?string $email): ?User
    {
        if (! $email) {
            return null;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            return null;
        }

        if (! $this->canAccessAnyPanel($user)) {
            return null;
        }

        return $user;
    }
}

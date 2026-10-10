<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use App\Notifications\OneTimePasswordNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class OneTimePassword
{
    public static function put(User $user, int $otp): void
    {
        Cache::put('otp_'.$user->email, [
            'id' => $user->id,
            'otp' => $otp,
        ], now()->addSeconds(config('auth.password_timeout')));
    }

    public static function get(User $user): ?array
    {
        return Cache::get('otp_'.$user->email);
    }

    public static function forget(User $user): bool
    {
        return Cache::forget('otp_'.$user->email);
    }

    public static function send(User $user): void
    {
        $otp = self::generate($user);

        $user->notify(new OneTimePasswordNotification($otp['otp']));
    }

    /**
     * @throws \Exception
     */
    public static function generate(User $user): array
    {
        $low = str_pad('1', 6, '1', STR_PAD_LEFT);
        $high = str_pad('9', 6, '9', STR_PAD_RIGHT);

        static::put($user, random_int((int) $low, (int) $high));

        return static::get($user);
    }

    /**
     * Attempt to authenticate a user using OTP.
     * Returns the JWT token if successful.
     * Returns false if the OTP is invalid.
     *
     * @param  string  $otp
     */
    public static function attempt(string $email, mixed $otp): int|string|false
    {
        $otp_data = static::get(User::where('email', $email)->first());

        if (! empty($otp_data) && $otp_data['otp'] == $otp) {

            $user = User::find($otp_data['id']);
            if ($user) {
                OneTimePassword::forget($user);
                Auth::login($user);

                return Auth::refresh();
            }
        }

        return false;
    }
}

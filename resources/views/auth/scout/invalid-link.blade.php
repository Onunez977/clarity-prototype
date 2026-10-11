@php($reason = $reason ?? null)
@php($flow = $flow ?? 'onboarding')
@php($pageTitle = match ($reason) {
    'used' => $flow === 'reset' ? 'Link Already Used' : 'Password Already Set',
    'ineligible' => 'Account Not Eligible',
    default => $expired ? 'Link Expired' : 'Invalid Link',
})
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#001166">
    <title>{{ $pageTitle }} — Clarity Scout</title>
    @vite(['resources/css/filament/app/theme.css'])
</head>
<body class="antialiased bg-gray-50 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="bg-white shadow-lg rounded-xl px-6 py-8 text-center">
            <h1 class="text-2xl font-semibold text-gray-900">
                @if ($reason === 'used')
                    {{ $flow === 'reset' ? 'This reset link has already been used' : 'You have already set your password' }}
                @elseif ($reason === 'ineligible')
                    This account cannot use Clarity Scout
                @else
                    {{ $expired ? 'This link has expired' : 'This link is invalid' }}
                @endif
            </h1>
            <p class="mt-3 text-sm text-gray-600">
                @if ($reason === 'used' && $flow === 'reset')
                    Open Clarity Scout and sign in with your new password. If you still cannot get in, ask your admin to send another password reset from Scout Users.
                @elseif ($reason === 'used')
                    This link has already been used to set your password. Open Clarity Scout and sign in with the password you set. If you cannot get in, contact your admin.
                @elseif ($reason === 'ineligible')
                    Ask your admin to check this user's setup in Scout Users.
                @elseif ($expired && $flow === 'reset')
                    Scout password reset links expire after 7 days. Ask your admin to send a new reset from Scout Users, or use Forgot password on the Scout login screen.
                @elseif ($expired)
                    Scout onboarding links expire after 3 days. Ask your admin to resend the Clarity Scout invite from Scout Users, then try the new email.
                @elseif ($flow === 'reset')
                    This password reset link is not valid. Ask your admin to send a new reset from Scout Users, or use Forgot password on the Scout login screen.
                @else
                    This set-password link is not valid. Ask your admin to resend the Clarity Scout invite from Scout Users, then open the new email on this device.
                @endif
            </p>
        </div>
    </div>
</body>
</html>

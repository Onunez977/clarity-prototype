<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#001166">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set Your Password — Clarity Scout</title>
    @vite(['resources/css/filament/app/theme.css'])
</head>
<body class="antialiased bg-gray-50 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="bg-white shadow-lg rounded-xl px-6 py-8">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-semibold text-gray-900">Set Your Password</h1>
                <p class="mt-2 text-sm text-gray-600">
                    Welcome to Clarity Scout, {{ $user->name ?: $user->email }}.
                    Choose a password to finish setting up your account.
                </p>
            </div>

            @if ($errors->any())
                <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ $signedUrl }}" class="space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" id="email" value="{{ $user->email }}" disabled
                        class="mt-1 block w-full rounded-md border-gray-300 bg-gray-100 px-3 py-2 text-gray-700 shadow-sm">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">New password</label>
                    <input type="password" id="password" name="password" required autocomplete="new-password"
                        class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                        class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>

                <button type="submit"
                    class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Set password & continue
                </button>
            </form>
        </div>
    </div>
</body>
</html>

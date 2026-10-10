<!DOCTYPE html>
<html lang="en" class="fi min-h-screen">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Redeem Invite — Insight LPR</title>
    @vite(['resources/css/filament/app/theme.css'])
</head>
<body class="fi-body fi-simple-main min-h-screen flex flex-col items-center justify-center gap-6 p-6">

    <img src="{{ asset('img/logo-dark.png') }}" alt="Insight LPR" class="h-10">

    <section class="fi-section w-full max-w-md rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6">
            <div class="mb-6 text-center">
                <h1 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                    Redeem Your Client Invite
                </h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    Enter the email address that received the invite and the 6-digit code to access the client portal.
                </p>
            </div>

            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm font-medium text-red-700 dark:bg-red-400/10 dark:text-red-400">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('client.invite.redeem') }}" class="grid gap-6">
                @csrf

                <div class="grid gap-y-2">
                    <label for="email" class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                        Email
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="email"
                            id="email"
                            name="email"
                            required
                            autofocus
                            :value="old('email', $email)"
                        />
                    </x-filament::input.wrapper>
                </div>

                <div class="grid gap-y-2">
                    <label for="code" class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                        Invite Code
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="text"
                            id="code"
                            name="code"
                            required
                            maxlength="6"
                            minlength="6"
                            autocapitalize="characters"
                            autocomplete="off"
                            inputmode="text"
                            class="text-center font-mono text-lg uppercase tracking-[0.5em]"
                            :value="old('code', $code)"
                        />
                    </x-filament::input.wrapper>
                </div>

                <button
                    type="submit"
                    class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2"
                    style="background-color: #FF6666;"
                    onmouseover="this.style.backgroundColor='#ff4d4d'"
                    onmouseout="this.style.backgroundColor='#FF6666'"
                >
                    Accept Invite
                </button>
            </form>
        </div>
    </section>
</body>
</html>

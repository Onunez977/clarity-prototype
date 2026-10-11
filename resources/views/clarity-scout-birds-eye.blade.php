<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#001166">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Org Map — Clarity Scout</title>
    @vite('resources/js/clarity-scout/birdseye.js')
</head>
<body class="antialiased">
    {{-- Same mount node as the driver app so the real App.vue mounts unchanged. --}}
    <div id="clarity-scout-app"></div>
    @auth
        @if (\App\Support\Otto\OttoAccess::userCanUseOtto(auth()->user()))
            <x-otto.island />
        @endif
    @endauth
    <script>
        window.__CLARITY_SCOUT_CONFIG__ = {
            googleMapsApiKey: '{{ config("services.google.maps.key") }}',
            // Flips lib/fetch + the stores to admin / org-wide mode (DEV-2116).
            birdsEye: true,
            // Admin-only "Back to Clarity" target — the SPA router owns the URL,
            // so the browser back button never returns to the Filament panel.
            backUrl: '{{ \Filament\Facades\Filament::getPanel('app')->getUrl() }}',
            user: @json(['org_id' => auth()->user()?->org_id]),
        };
    </script>
</body>
</html>

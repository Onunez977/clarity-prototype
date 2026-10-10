<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#001166">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Clarity Scout">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Clarity Scout</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    @vite('resources/js/clarity-scout/main.js')
</head>
<body class="antialiased">
    <div id="clarity-scout-app"></div>
    @isset($forceAuth)
    <script>window.__CLARITY_SCOUT_FORCE_AUTH__ = @json($forceAuth);</script>
    @endisset
    <script>
        window.__CLARITY_SCOUT_CONFIG__ = {
            googleMapsApiKey: '{{ config("services.google.maps.key") }}',
        };

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/clarity-scout-sw.js').catch(() => {});
        }
    </script>
</body>
</html>

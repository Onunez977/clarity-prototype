<html lang="en">
<head>
    <meta charset="utf-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>{{ $name }}</title>

    @vite(['resources/css/filament/app/theme.css'])
</head>
<body class="m-2">
    @yield('content')
</body>

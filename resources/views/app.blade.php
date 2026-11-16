<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">

    {{-- FOUC prevention: apply stored theme before CSS paints --}}
    <script>
        (function () {
            var pref = localStorage.getItem('theme') || 'system';
            var resolved = pref === 'system'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : pref;
            document.documentElement.setAttribute('data-bs-theme', resolved);
        }());
    </script>

    {{-- Bootstrap, BootstrapVueNext and Remix Icon load from CDN — do not add them to npm --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-vue-next@1.2.2/dist/bootstrap-vue-next.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.9.1/fonts/remixicon.css" rel="stylesheet">

    {{-- Signed out: only the login routes (config/ziggy.php) --}}
    @routes(auth()->check() ? null : 'guest')
    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body>@inertia</body>
</html>

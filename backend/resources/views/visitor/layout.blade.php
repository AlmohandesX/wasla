<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#14213D">
    @yield('head')
    <title>{{ __('visitor.title') }}</title>
    <link rel="preload" href="/fonts/manrope-800.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/visitor.css">
</head>
<body>
    <main class="note">
        <img class="brand" src="/img/beni-bul-logo.svg" alt="Beni Bul" width="288" height="216">
        @yield('content')
    </main>
    <footer class="foot">{{ __('visitor.footer') }}</footer>
    @yield('scripts')
</body>
</html>

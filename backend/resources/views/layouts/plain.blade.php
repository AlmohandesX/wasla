{{-- A plain Beni Bul page (home and errors): the visitor look, no inline script or style, so it renders under the strict CSP. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#14213D">
    @yield('head')
    <title>@yield('title') - Beni Bul</title>
    <link rel="preload" href="/fonts/manrope-800.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/visitor.css">
</head>
<body>
    <header class="band">
        <img class="brand" src="/img/beni-bul-logo-white.svg" alt="Beni Bul" width="288" height="216">
    </header>
    <div class="page">
        <main class="note">
            @yield('content')
        </main>
    </div>
</body>
</html>

{{-- A plain WASLA page (home and errors): the visitor look, no inline script or style, so it renders under the strict CSP. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#4169E1">
    @yield('head')
    <title>@yield('title') - WASLA</title>
    <link rel="preload" href="/fonts/figtree-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/visitor.css">
</head>
<body>
    <main class="note">
        @yield('content')
    </main>
</body>
</html>

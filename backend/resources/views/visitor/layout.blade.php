<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#14213D">
    @yield('head')
    <title>{{ __('visitor.title') }}</title>
    <link rel="preload" href="/fonts/manrope-800.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/css/visitor.css">
</head>
<body>
    {{-- The navy band, as on the app's Home: the logo and the TR | EN switch. It carries no tag data, so neutral
         pages stay identical for every tag. --}}
    <header class="band">
        <img class="brand" src="/img/beni-bul-logo-white.svg" alt="Beni Bul" width="288" height="216">
        <nav class="lang" aria-label="{{ __('visitor.language') }}">
            @foreach (\App\Http\Middleware\VisitorPageHeaders::LOCALES as $locale)
                @if (! $loop->first)<span aria-hidden="true">|</span>@endif
                {{-- Relative, so the page carries no token or address. --}}
                <a href="?lang={{ $locale }}"
                   lang="{{ $locale }}" hreflang="{{ $locale }}"
                   @if (app()->getLocale() === $locale) aria-current="true" @endif>{{ strtoupper($locale) }}</a>
            @endforeach
        </nav>
    </header>
    <div class="page">
        <main class="note">
            @yield('content')
        </main>
        <footer class="foot">{{ __('visitor.footer') }}</footer>
    </div>
    @yield('scripts')
</body>
</html>

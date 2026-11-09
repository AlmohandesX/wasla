<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#14213D">
    <title>{{ __('wasla.reset_page_title') }} - Beni Bul</title>
    <link rel="stylesheet" href="/css/visitor.css">
    <link rel="stylesheet" href="/css/reset-password.css">
</head>
<body>
    <header class="band">
        <img class="brand" src="/img/beni-bul-logo-white.svg" alt="Beni Bul" width="288" height="216">
    </header>
    <div class="page">
    <main class="note">
        <h1 class="message quiet">{{ $done ? __('wasla.password_reset') : __('wasla.reset_page_title') }}</h1>

        @if ($done)
            <p class="body">{{ __('wasla.reset_done_hint') }}</p>
        @elseif ($showForm)
            <p class="body">{{ __('wasla.reset_page_intro') }}</p>
        @endif

        @if (count($errors_list) > 0)
            <div class="error" role="alert">
                @foreach ($errors_list as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        @if ($showForm && ! $done)
            <form method="post" action="{{ url('/reset-password') }}" class="reset-form">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="email" value="{{ $email }}">

                <label class="field" for="password">{{ __('wasla.reset_field_password') }}</label>
                <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required>
                <p class="hint">{{ __('wasla.reset_field_hint') }}</p>

                <label class="field" for="password_confirmation">{{ __('wasla.reset_field_confirm') }}</label>
                <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="128" required>

                <button class="action" type="submit">{{ __('wasla.reset_submit') }}</button>
            </form>
        @endif

        @if ($email !== '' && $token !== '' && ! $done)
            <a class="back" href="{{ $appLink }}">{{ __('wasla.reset_open_app') }}</a>
        @endif
    </main>
    </div>
</body>
</html>

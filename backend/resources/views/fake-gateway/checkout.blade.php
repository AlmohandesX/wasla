<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('wasla.fake_checkout_title') }} - WASLA</title>
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body>
    <main>
        <h1>{{ __('wasla.fake_checkout_title') }}</h1>
        <p>{{ __('wasla.fake_checkout_body') }}</p>
        <form method="post" action="/fake-gateway/checkout/{{ $reference }}/pay">
            @csrf
            <button type="submit">{{ __('wasla.fake_checkout_pay') }}</button>
        </form>
        <form method="post" action="/fake-gateway/checkout/{{ $reference }}/fail">
            @csrf
            <button type="submit">{{ __('wasla.fake_checkout_fail') }}</button>
        </form>
    </main>
</body>
</html>

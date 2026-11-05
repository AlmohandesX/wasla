<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('wasla.payment_return_title') }} - WASLA</title>
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body>
    <main>
        <h1>{{ __('wasla.payment_return_title') }}</h1>
        <p>{{ __('wasla.payment_return_body') }}</p>
    </main>
</body>
</html>

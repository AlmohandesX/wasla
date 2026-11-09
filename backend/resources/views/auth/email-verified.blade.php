<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('wasla.email_verified_title') }} - Beni Bul</title>
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body>
    <main>
        <h1>{{ __('wasla.email_verified_title') }}</h1>
        <p>{{ __('wasla.email_verified_body') }}</p>
    </main>
</body>
</html>

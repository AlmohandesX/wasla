<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('wasla.email_verified_title') }} - WASLA</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, sans-serif; background: #fff; color: #111827; }
        main { max-width: 28rem; padding: 2rem 1.5rem; text-align: center; }
        h1 { color: #2f4fb8; font-size: 1.5rem; }
        p { line-height: 1.5; }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('wasla.email_verified_title') }}</h1>
        <p>{{ __('wasla.email_verified_body') }}</p>
    </main>
</body>
</html>

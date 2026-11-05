<?php

use App\Support\ProductionGuard;

return [
    // Raw values the ProductionGuard checks. Captured here so config:cache keeps them.
    'guard_environment' => collect(ProductionGuard::variableNames())
        ->mapWithKeys(fn (string $key) => [$key => env($key)])
        ->all(),

    // Version of the privacy notice owners accept at registration.
    'privacy_notice_version' => env('PRIVACY_NOTICE_VERSION', '1'),

    // Deep link or page that opens the mobile reset-password screen.
    'password_reset_url' => env('PASSWORD_RESET_URL', 'wasla://reset-password'),

    // Constant bcrypt hash (cost 12) checked when a login email is unknown, so timing matches a
    // wrong password. Keep its cost equal to BCRYPT_ROUNDS (12 in production).
    'dummy_password_hash' => '$2y$12$sjwYX806TjRZVVezGsnvZuD0epZoDxEfxQoCnRZid8R5qiiSDsEua',

    'tag_base_url' => env('TAG_BASE_URL', 'http://localhost:8000/t'),

    'moderation_daily_cap' => (int) env('MODERATION_DAILY_CAP', 1000),

    'payment_gateway' => env('PAYMENT_GATEWAY', 'fake'),

    // Comma separated proxy IPs or CIDRs, or "*" when the VPS sits behind a trusted load balancer only.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // Storage is UTC; this zone is for display and scheduling only.
    'display_timezone' => 'Europe/Istanbul',
];

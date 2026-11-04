<?php

use App\Support\ProductionGuard;

return [
    // Raw values the ProductionGuard checks. Captured here so config:cache keeps them.
    'guard_environment' => collect(ProductionGuard::variableNames())
        ->mapWithKeys(fn (string $key) => [$key => env($key)])
        ->all(),

    'tag_base_url' => env('TAG_BASE_URL', 'http://localhost:8000/t'),

    'moderation_daily_cap' => (int) env('MODERATION_DAILY_CAP', 1000),

    'payment_gateway' => env('PAYMENT_GATEWAY', 'fake'),

    // Comma separated proxy IPs or CIDRs, or "*" when the VPS sits behind a trusted load balancer only.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // Storage is UTC; this zone is for display and scheduling only.
    'display_timezone' => 'Europe/Istanbul',
];

<?php

use App\Support\ProductionGuard;

return [
    // Raw values the ProductionGuard checks. Captured here so config:cache keeps them.
    'guard_environment' => collect(ProductionGuard::variableNames())
        ->mapWithKeys(fn (string $key) => [$key => env($key)])
        ->all(),

    // Version of the privacy notice owners accept at registration.
    'privacy_notice_version' => env('PRIVACY_NOTICE_VERSION', '1'),

    // Page the reset email links to. The default is the web page on this server (works from any mail app and
    // without the app installed); it offers an "open the app" link to wasla://reset-password.
    'password_reset_url' => env('PASSWORD_RESET_URL') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/reset-password',

    // Constant bcrypt hash (cost 12) checked when a login email is unknown, so timing matches a
    // wrong password. Keep its cost equal to BCRYPT_ROUNDS (12 in production).
    'dummy_password_hash' => '$2y$12$sjwYX806TjRZVVezGsnvZuD0epZoDxEfxQoCnRZid8R5qiiSDsEua',

    'tag_base_url' => env('TAG_BASE_URL', 'http://localhost:8000/t'),

    'moderation' => [
        'api_key' => env('OPENAI_API_KEY'),
        'endpoint' => 'https://api.openai.com/v1/moderations',
        'model' => 'omni-moderation-latest',
        'timeout' => 3,
        // Block when any category score reaches its threshold. Add per-category overrides by name,
        // for example 'harassment/threatening' => 0.5.
        'threshold' => 0.7,
        'thresholds' => [],
        // Provider calls per Istanbul day; past it, messages pass on the blacklist alone and are flagged.
        'daily_cap' => (int) env('MODERATION_DAILY_CAP', 5000),
    ],

    'chat' => [
        // Adds a consent checkbox to the visitor chat. Visitors who decline get blacklist-only moderation.
        'consent_required' => (bool) env('CHAT_CONSENT_REQUIRED', false),
        'messages_per_window' => 10,
        'window_minutes' => 10,
        'new_threads_per_hour' => 3,
        'cookie_days' => 30,
    ],

    'payment_gateway' => env('PAYMENT_GATEWAY', 'fake'),

    // How long a hosted checkout stays payable. While a payment is pending and younger than this, the same tag
    // cannot start another renewal (409 renewal_pending). iyzico's checkout token lives about 30 minutes, and
    // PaytrGateway sends this same value as timeout_limit, so neither provider can accept a payment after the window.
    'checkout_lifetime_minutes' => (int) env('CHECKOUT_LIFETIME_MINUTES', 30),

    // Version of the distance-selling pre-information and contract text a buyer accepts at renewal.
    'legal_text_version' => env('LEGAL_TEXT_VERSION', '1'),

    // Comma separated proxy IPs or CIDRs, or "*" when the VPS sits behind a trusted load balancer only.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // Storage is UTC; this zone is for display and scheduling only.
    'display_timezone' => 'Europe/Istanbul',
];

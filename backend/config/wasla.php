<?php

use App\Support\CheckoutLifetime;
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

    // Read only by SuperAdminSeeder. Set the password for the one seeding run, then remove it from .env.
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'username' => env('SUPER_ADMIN_USERNAME'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    // Google Sign-In: the OAuth client IDs an ID token may be issued to (comma-separated). The app asks Google for
    // a token with the Web client ID, so that one must be listed.
    'google' => [
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))))),
    ],

    'tag_base_url' => env('TAG_BASE_URL', 'http://localhost:8000/t'),

    'moderation' => [
        'api_key' => env('OPENAI_API_KEY'),
        'endpoint' => 'https://api.openai.com/v1/moderations',
        'model' => 'omni-moderation-latest',
        'timeout' => 3,
        // Block when any category score reaches its threshold. Add per-category overrides by name,
        // for example 'harassment/threatening' => 0.5.
        'threshold' => 0.7,
        // Stricter for insults and threats aimed at the owner (owner's call, 2026-11-11). Ordinary parking messages
        // scored 0.03 or less here, while "يا ابن الكلب" scored 0.40. Violence stays at the default 0.7 on purpose:
        // honest accident reports ("a car hit yours, the window is broken") scored up to 0.35.
        'thresholds' => [
            'harassment' => 0.35,
            'harassment/threatening' => 0.3,
            'hate' => 0.4,
        ],
        // Provider calls per Istanbul day; past it, messages pass on the blacklist alone and are flagged.
        'daily_cap' => (int) env('MODERATION_DAILY_CAP', 5000),
        // Owner texts (status, default message, contact labels) one owner may have checked per Istanbul day; past it
        // the write is refused, so no single account can spend the platform cap.
        'owner_daily_cap' => 50,
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
    // cannot start another renewal (409 renewal_pending). Clamped to 30..60: iyzico's checkout token lives a fixed
    // 1800 s, so less than 30 would allow a second start while the first can still be paid, and PaytrGateway sends
    // this same value as timeout_limit. An empty, zero or negative value falls back to 30 instead of turning the
    // guard off; ProductionGuard refuses out-of-range values outright.
    'checkout_lifetime_minutes' => CheckoutLifetime::fromEnv(env('CHECKOUT_LIFETIME_MINUTES')),

    // Version of the distance-selling pre-information and contract text a buyer accepts at renewal.
    'legal_text_version' => env('LEGAL_TEXT_VERSION', '1'),

    // Comma separated proxy IPs or CIDRs, or "*" when the VPS sits behind a trusted load balancer only.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // Storage is UTC; this zone is for display and scheduling only.
    'display_timezone' => 'Europe/Istanbul',
];

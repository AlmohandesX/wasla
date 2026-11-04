<?php

namespace App\Support;

use RuntimeException;

/**
 * Refuses to boot the app in an unsafe or incomplete configuration.
 * Pure: it receives the environment as an array so it can be tested directly.
 */
class ProductionGuard
{
    public const ENVIRONMENTS = ['local', 'testing', 'production'];

    private const ALWAYS_REQUIRED_IN_PRODUCTION = [
        'APP_KEY', 'APP_URL', 'TAG_BASE_URL',
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'OPENAI_API_KEY', 'MODERATION_DAILY_CAP', 'PAYMENT_GATEWAY',
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET',
        'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS',
    ];

    private const GATEWAY_KEYS = [
        'iyzico' => ['IYZICO_API_KEY', 'IYZICO_SECRET_KEY'],
        'paytr' => ['PAYTR_MERCHANT_ID', 'PAYTR_MERCHANT_KEY', 'PAYTR_MERCHANT_SALT'],
    ];

    /** Every variable the guard reads, so config can capture them even when config is cached. */
    public static function variableNames(): array
    {
        return array_values(array_unique(array_merge(
            ['APP_ENV', 'APP_DEBUG'],
            self::ALWAYS_REQUIRED_IN_PRODUCTION,
            ...array_values(self::GATEWAY_KEYS),
        )));
    }

    /** @throws RuntimeException naming every problem found */
    public static function validate(array $env): void
    {
        $problems = [];
        $appEnv = (string) ($env['APP_ENV'] ?? '');

        if (! in_array($appEnv, self::ENVIRONMENTS, true)) {
            $problems[] = 'APP_ENV must be one of: '.implode(', ', self::ENVIRONMENTS).'.';
        }

        if ($appEnv === 'production') {
            $problems = array_merge($problems, self::productionProblems($env));
        }

        if ($problems !== []) {
            throw new RuntimeException("Unsafe configuration:\n- ".implode("\n- ", $problems));
        }
    }

    private static function productionProblems(array $env): array
    {
        $problems = [];
        $present = fn (string $key): bool => isset($env[$key]) && trim((string) $env[$key]) !== '';

        if (filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $problems[] = 'APP_DEBUG must be false in production.';
        }

        $required = self::ALWAYS_REQUIRED_IN_PRODUCTION;
        $gateway = strtolower((string) ($env['PAYMENT_GATEWAY'] ?? ''));

        if ($gateway === 'fake') {
            $problems[] = 'PAYMENT_GATEWAY must not be fake in production.';
        } elseif ($gateway !== '' && ! isset(self::GATEWAY_KEYS[$gateway])) {
            $problems[] = 'PAYMENT_GATEWAY must be one of: '.implode(', ', array_keys(self::GATEWAY_KEYS)).'.';
        }
        $required = array_merge($required, self::GATEWAY_KEYS[$gateway] ?? []);

        foreach ($required as $key) {
            if (! $present($key)) {
                $problems[] = "$key is required in production.";
            }
        }

        foreach (['APP_URL', 'TAG_BASE_URL'] as $key) {
            if ($present($key) && ! str_starts_with(strtolower(trim((string) $env[$key])), 'https://')) {
                $problems[] = "$key must start with https://.";
            }
        }

        if ($present('MAIL_MAILER') && in_array($env['MAIL_MAILER'], ['log', 'array'], true)) {
            $problems[] = 'MAIL_MAILER must deliver real mail in production.';
        }

        return $problems;
    }
}

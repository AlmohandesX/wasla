<?php

namespace App\Support;

/** Overwrites the billing national ID and phone wherever they appear in a log context, at any depth. */
class RedactNationalId
{
    public const KEYS = ['billing_national_id', 'billing_phone'];

    public static function from(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array($key, self::KEYS, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::from($value);
            }
        }

        return $context;
    }
}

<?php

namespace App\Support;

/** Overwrites the billing national ID wherever it appears in a log context, at any depth. */
class RedactNationalId
{
    public const KEY = 'billing_national_id';

    public static function from(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($key === self::KEY) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::from($value);
            }
        }

        return $context;
    }
}

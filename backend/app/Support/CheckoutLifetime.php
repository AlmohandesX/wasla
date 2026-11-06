<?php

namespace App\Support;

/** Minutes a hosted checkout stays payable, from CHECKOUT_LIFETIME_MINUTES. Never turns the duplicate guard off. */
class CheckoutLifetime
{
    public const MIN = 30;

    public const MAX = 60;

    /** Empty, non-numeric, zero, negative or out-of-range input lands on the nearest allowed value (default 30). */
    public static function fromEnv(mixed $raw): int
    {
        $minutes = filter_var($raw, FILTER_VALIDATE_INT);

        return $minutes === false ? self::MIN : max(self::MIN, min(self::MAX, $minutes));
    }
}

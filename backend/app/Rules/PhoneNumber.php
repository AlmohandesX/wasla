<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Turkish numbers (05xx, 5xx, +90) and international E.164 numbers. */
class PhoneNumber implements ValidationRule
{
    /** Returns the E.164 form (+905321234567), or null when the number is not valid. */
    public static function normalize(string $raw): ?string
    {
        $number = preg_replace('/[\s\-().]/', '', $raw);

        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }
        if (preg_match('/^0?([2-5]\d{9})$/', $number, $m) === 1) {
            return '+90'.$m[1];
        }
        if (preg_match('/^\+[1-9]\d{7,14}$/', $number) !== 1) {
            return null;
        }
        if (str_starts_with($number, '+90') && preg_match('/^\+90[2-5]\d{9}$/', $number) !== 1) {
            return null;
        }

        return $number;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::normalize($value) === null) {
            $fail('validation.regex')->translate();
        }
    }
}

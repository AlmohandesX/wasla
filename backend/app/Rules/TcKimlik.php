<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Turkish national ID: 11 digits, first not 0, with the two official check digits. */
class TcKimlik implements ValidationRule
{
    public static function isValid(string $id): bool
    {
        if (! preg_match('/^[1-9][0-9]{10}$/', $id)) {
            return false;
        }
        $d = array_map('intval', str_split($id));
        $tenth = (($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7 - ($d[1] + $d[3] + $d[5] + $d[7])) % 10;
        if (($tenth + 10) % 10 !== $d[9]) {
            return false;
        }

        return array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('wasla.invalid_national_id')->translate();
        }
    }
}

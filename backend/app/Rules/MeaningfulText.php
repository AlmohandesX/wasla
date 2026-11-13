<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Rejects text made only of whitespace, non-breaking spaces and invisible format characters. */
class MeaningfulText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/[^\s\p{Z}\p{C}]/u', $value) !== 1) {
            $fail('validation.required')->translate();
        }
    }
}

<?php

namespace App\Services\Payments;

/** Money as integer kurus and provider decimals as text. Floats never enter the conversion. */
final class Kurus
{
    public static function toDecimal(int $kurus): string
    {
        return intdiv($kurus, 100).'.'.str_pad((string) ($kurus % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Null for anything that is not an exact, non-negative amount: nothing is rounded or guessed. */
    public static function fromDecimal(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,12})(?:[.,](\d+))?$/', $value, $m)) {
            return null;
        }

        $fraction = rtrim($m[2] ?? '', '0');

        return strlen($fraction) > 2 ? null : (int) $m[1] * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Decodes a provider's JSON with the named amount keys kept as their exact text, because a JSON number
     * would otherwise become a float and lose the form a signature is computed over.
     */
    public static function decodeJson(string $body, array $amountKeys): ?array
    {
        $keys = implode('|', array_map(fn (string $key) => preg_quote($key, '/'), $amountKeys));
        // A quote inside a JSON string is escaped, so this cannot match inside a string value.
        $quoted = preg_replace('/"('.$keys.')"(\s*:\s*)(-?\d+(?:\.\d+)?)/', '"$1"$2"$3"', $body);
        $data = json_decode($quoted ?? '', true);

        return is_array($data) ? $data : null;
    }
}

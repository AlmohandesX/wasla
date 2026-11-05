<?php

namespace App\Services\Moderation;

class TextNormalizer
{
    // "@" folds to "o" (the brief's fixture k@tukelime); "a"-style evasions like "@mk" are a known gap.
    private const SUBSTITUTIONS = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'o', '$' => 's',
    ];

    public function normalize(string $text): string
    {
        // Zero-width characters and the combining dot that decomposed "İ" leaves behind.
        $text = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{0307}]/u', '', $text) ?? $text;

        // Turkish casing must be handled before lowercasing: İ -> i, I -> ı.
        $text = strtr($text, ['İ' => 'i', 'I' => 'ı']);
        $text = mb_strtolower($text, 'UTF-8');

        $text = strtr($text, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);
        $text = strtr($text, self::SUBSTITUTIONS);

        // Repeated characters (letters and whitespace) collapse to one.
        $text = preg_replace('/(.)\1+/us', '$1', $text) ?? $text;

        return trim($text);
    }
}

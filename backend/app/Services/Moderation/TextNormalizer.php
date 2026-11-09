<?php

namespace App\Services\Moderation;

class TextNormalizer
{
    private const SUBSTITUTIONS = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's',
    ];

    /**
     * Arabic letter variants folded to one form, so a term matches however the writer spelled the hamza,
     * alef or final letters: أ إ آ ٱ → ا, ى ی → ي, ة → ه, ؤ → و, ئ → ي, ک → ك.
     */
    private const ARABIC_LETTERS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي', 'ی' => 'ي', 'ئ' => 'ي',
        'ة' => 'ه', 'ؤ' => 'و', 'ک' => 'ك',
    ];

    public function normalize(string $text): string
    {
        // Zero-width characters and the combining dot that decomposed "İ" leaves behind.
        $text = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{0307}]/u', '', $text) ?? $text;

        // Arabic short vowels (tashkeel), shadda, sukun, combining hamza, superscript alef, Quranic marks, and
        // the tatweel stretch (ـ). Removed before the letter folding so "ا" + combining hamza ends up as "ا".
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, self::ARABIC_LETTERS);

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

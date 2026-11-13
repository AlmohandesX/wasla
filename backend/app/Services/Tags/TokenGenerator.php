<?php

namespace App\Services\Tags;

class TokenGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** Canonical token shape; the D modifier makes a trailing newline fail. */
    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[0-9A-Za-z]{16}$/D', $token) === 1;
    }

    /** 16 base62 characters (about 95 bits) from random_int, which is cryptographically secure. */
    public function generate(): string
    {
        $token = '';
        for ($i = 0; $i < 16; $i++) {
            $token .= self::ALPHABET[random_int(0, 61)];
        }

        return $token;
    }
}

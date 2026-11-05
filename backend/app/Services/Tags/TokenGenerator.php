<?php

namespace App\Services\Tags;

class TokenGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

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

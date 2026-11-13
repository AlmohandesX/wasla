<?php

namespace App\Services\Auth\Google;

/** What a verified Google ID token says about the person. The email is lowercase and Google-verified. */
final class GoogleIdentity
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly string $name,
    ) {}
}

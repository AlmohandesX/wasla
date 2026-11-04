<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\RateLimiter;

class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    public function tooManyAttempts(string $email, string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->accountKey($email), self::MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts($this->ipKey($ip), self::MAX_ATTEMPTS);
    }

    public function hit(string $email, string $ip): void
    {
        RateLimiter::hit($this->accountKey($email), self::DECAY_SECONDS);
        RateLimiter::hit($this->ipKey($ip), self::DECAY_SECONDS);
    }

    /** Success clears the account counter only, so an attacker's own login cannot reset the IP counter. */
    public function clearAccount(string $email): void
    {
        RateLimiter::clear($this->accountKey($email));
    }

    public function availableIn(string $email, string $ip): int
    {
        return max(
            RateLimiter::availableIn($this->accountKey($email)),
            RateLimiter::availableIn($this->ipKey($ip)),
        );
    }

    private function accountKey(string $email): string
    {
        return 'login:acct:'.hash('sha256', mb_strtolower(trim($email)));
    }

    private function ipKey(string $ip): string
    {
        return 'login:ip:'.$ip;
    }
}

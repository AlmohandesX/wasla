<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Locks one account for 15 minutes after 5 failed logins. Deliberately keyed on the account only, never the
 * IP address: Turkish mobile networks put many customers behind one shared address (CGNAT), so an IP lock
 * would shut out everyone on it. Per-IP burst control stays with the route's `throttle:auth` limiter.
 */
class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    public function tooManyAttempts(string $email): bool
    {
        return RateLimiter::tooManyAttempts($this->lockKey($email), 1);
    }

    /** The counter window starts at the first failure; the lock starts at the failure that reaches the limit. */
    public function hit(string $email): void
    {
        $key = $this->accountKey($email);
        RateLimiter::hit($key, self::DECAY_SECONDS);

        if (RateLimiter::attempts($key) >= self::MAX_ATTEMPTS) {
            RateLimiter::clear($this->lockKey($email));
            RateLimiter::hit($this->lockKey($email), self::DECAY_SECONDS);
        }
    }

    public function clearAccount(string $email): void
    {
        RateLimiter::clear($this->accountKey($email));
        RateLimiter::clear($this->lockKey($email));
    }

    public function availableIn(string $email): int
    {
        return RateLimiter::availableIn($this->lockKey($email));
    }

    private function lockKey(string $email): string
    {
        return $this->accountKey($email).':lock';
    }

    private function accountKey(string $email): string
    {
        return 'login:acct:'.hash('sha256', mb_strtolower(trim($email)));
    }
}

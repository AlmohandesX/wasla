<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\RateLimiter;

class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    public function tooManyAttempts(string $email, string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->lockKey($this->accountKey($email)), 1)
            || RateLimiter::tooManyAttempts($this->lockKey($this->ipKey($ip)), 1);
    }

    public function hit(string $email, string $ip): void
    {
        $this->count($this->accountKey($email));
        $this->count($this->ipKey($ip));
    }

    /** Success clears the account counter only, so an attacker's own login cannot reset the IP counter. */
    public function clearAccount(string $email): void
    {
        RateLimiter::clear($this->accountKey($email));
        RateLimiter::clear($this->lockKey($this->accountKey($email)));
    }

    public function availableIn(string $email, string $ip): int
    {
        return max(
            RateLimiter::availableIn($this->lockKey($this->accountKey($email))),
            RateLimiter::availableIn($this->lockKey($this->ipKey($ip))),
        );
    }

    /** The counter window starts at the first failure; the lock starts at the failure that reaches the limit. */
    private function count(string $key): void
    {
        RateLimiter::hit($key, self::DECAY_SECONDS);

        if (RateLimiter::attempts($key) >= self::MAX_ATTEMPTS) {
            RateLimiter::clear($this->lockKey($key));
            RateLimiter::hit($this->lockKey($key), self::DECAY_SECONDS);
        }
    }

    private function lockKey(string $key): string
    {
        return $key.':lock';
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

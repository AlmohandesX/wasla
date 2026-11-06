<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;

/** A checkout for this tag is still open, so a second one would risk charging the owner twice. */
class RenewalPending extends TagException
{
    public string $errorCode = 'renewal_pending';

    public int $status = 409;

    /** @param  int|null  $paymentId  set only when the open payment belongs to the person asking */
    /** Whole seconds left, never negative: a phone with a wrong clock can use this instead of the absolute time. */
    public function secondsLeft(): int
    {
        return max(0, (int) now()->diffInSeconds($this->opensUntil, false));
    }

    public function __construct(public readonly ?int $paymentId, public readonly CarbonInterface $opensUntil)
    {
        parent::__construct();
    }
}

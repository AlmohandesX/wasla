<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;

/** A checkout for this tag is still open, so a second one would risk charging the owner twice. */
class RenewalPending extends TagException
{
    public string $errorCode = 'renewal_pending';

    public int $status = 409;

    /** @param  int|null  $paymentId  set only when the open payment belongs to the person asking */
    public function __construct(public readonly ?int $paymentId, public readonly CarbonInterface $opensUntil)
    {
        parent::__construct();
    }
}

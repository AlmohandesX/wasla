<?php

namespace App\Exceptions;

/** A checkout for this tag is still open, so a second one would risk charging the owner twice. */
class RenewalPending extends TagException
{
    public string $errorCode = 'renewal_pending';

    public int $status = 409;

    public function __construct(public readonly int $paymentId)
    {
        parent::__construct();
    }
}

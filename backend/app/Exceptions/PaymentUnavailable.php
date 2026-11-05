<?php

namespace App\Exceptions;

/** The payment provider could not open a checkout. */
class PaymentUnavailable extends TagException
{
    public string $errorCode = 'payment_unavailable';

    public int $status = 502;
}

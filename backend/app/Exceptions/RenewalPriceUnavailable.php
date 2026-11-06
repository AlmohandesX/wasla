<?php

namespace App\Exceptions;

/** No valid renewal price is configured; charging a guessed amount would be worse than refusing. */
class RenewalPriceUnavailable extends TagException
{
    public string $errorCode = 'renewal_price_unavailable';

    public int $status = 503;
}

<?php

namespace App\Exceptions;

class SubscriptionExpired extends TagException
{
    public string $errorCode = 'subscription_expired';

    public int $status = 402;
}

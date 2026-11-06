<?php

namespace App\Exceptions;

class ChatRateLimited extends TagException
{
    public string $errorCode = 'rate_limited';

    public int $status = 429;
}

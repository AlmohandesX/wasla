<?php

namespace App\Exceptions;

class MessageBlocked extends TagException
{
    public string $errorCode = 'message_blocked';

    public int $status = 422;
}

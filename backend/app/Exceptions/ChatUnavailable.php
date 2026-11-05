<?php

namespace App\Exceptions;

/** Replying needs a car with a linked, active tag; an unlinked car keeps history only. */
class ChatUnavailable extends TagException
{
    public string $errorCode = 'chat_unavailable';

    public int $status = 403;
}

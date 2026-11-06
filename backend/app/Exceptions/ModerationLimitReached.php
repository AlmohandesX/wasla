<?php

namespace App\Exceptions;

/** The owner used up today's moderation budget for their own texts (status, default message, contact labels). */
class ModerationLimitReached extends TagException
{
    public string $errorCode = 'moderation_limit';

    public int $status = 429;
}

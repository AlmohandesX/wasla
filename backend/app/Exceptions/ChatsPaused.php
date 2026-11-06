<?php

namespace App\Exceptions;

/** The owner switched off new chats for this car; threads that already exist keep working. */
class ChatsPaused extends TagException
{
    public string $errorCode = 'chats_paused';

    public int $status = 403;
}

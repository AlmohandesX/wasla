<?php

namespace App\Exceptions;

class InvalidGoogleToken extends TagException
{
    public string $errorCode = 'invalid_google_token';

    public int $status = 401;
}

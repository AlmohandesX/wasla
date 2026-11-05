<?php

namespace App\Exceptions;

class InvalidPassword extends TagException
{
    public string $errorCode = 'invalid_password';

    public int $status = 422;
}

<?php

namespace App\Exceptions;

class GoogleAccountConflict extends TagException
{
    public string $errorCode = 'google_account_conflict';

    public int $status = 409;
}

<?php

namespace App\Exceptions;

class AdminAccountInApp extends TagException
{
    public string $errorCode = 'admin_account';

    public int $status = 403;
}

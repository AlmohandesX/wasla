<?php

namespace App\Exceptions;

class AdminAccountProtected extends TagException
{
    public string $errorCode = 'admin_account_protected';

    public int $status = 403;
}

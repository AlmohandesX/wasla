<?php

namespace App\Exceptions;

class TagRevoked extends TagException
{
    public string $errorCode = 'tag_revoked';

    public int $status = 410;
}

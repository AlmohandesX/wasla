<?php

namespace App\Exceptions;

class TagNotFound extends TagException
{
    public string $errorCode = 'tag_not_found';

    public int $status = 404;
}

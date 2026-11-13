<?php

namespace App\Exceptions;

class TagAlreadyClaimed extends TagException
{
    public string $errorCode = 'tag_already_claimed';

    public int $status = 409;
}

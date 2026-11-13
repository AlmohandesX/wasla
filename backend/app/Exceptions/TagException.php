<?php

namespace App\Exceptions;

use RuntimeException;

/** Domain error with a stable machine code that the HTTP layer renders as JSON. */
abstract class TagException extends RuntimeException
{
    public string $errorCode;

    public int $status = 422;

    public function __construct()
    {
        parent::__construct($this->errorCode);
    }
}

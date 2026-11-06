<?php

namespace App\Exceptions;

use DomainException;

/** A refused admin action. The message is already translated and safe to show to the admin. */
class AdminActionException extends DomainException
{
    public static function because(string $key): static
    {
        return new static(__('admin.'.$key));
    }
}

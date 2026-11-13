<?php

namespace App\Exceptions;

/** The car has no activated tag, so there is nothing to renew. */
class RenewalNoTag extends TagException
{
    public string $errorCode = 'renewal_no_tag';

    public int $status = 422;
}

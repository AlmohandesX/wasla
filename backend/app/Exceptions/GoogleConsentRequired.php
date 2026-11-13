<?php

namespace App\Exceptions;

class GoogleConsentRequired extends TagException
{
    public string $errorCode = 'privacy_consent_required';

    public int $status = 422;
}

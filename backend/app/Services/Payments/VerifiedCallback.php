<?php

namespace App\Services\Payments;

/** A callback whose signature checked out; it only identifies the payment, status comes from fetchStatus. */
final class VerifiedCallback
{
    public function __construct(public readonly string $reference) {}
}

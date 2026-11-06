<?php

namespace App\Services\Payments;

/** A callback the driver proved came from the provider; it only identifies the payment, status comes from fetchStatus. */
final class VerifiedCallback
{
    public function __construct(public readonly string $reference) {}
}

<?php

namespace App\Services\Payments;

/** What a driver needs to open a hosted checkout. Amounts are integer kurus. */
final class CheckoutRequest
{
    public function __construct(
        public readonly string $merchantReference, // our unique alphanumeric id; a driver may send it to the provider
        public readonly int $amountKurus,
        public readonly string $currency,
        public readonly string $buyerEmail,
        public readonly string $buyerIp,
        public readonly string $locale,
        public readonly string $description,
        public readonly BillingDetails $billing,
        public readonly string $buyerId,
    ) {}
}

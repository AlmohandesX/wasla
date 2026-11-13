<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;

final class ProviderPaymentStatus
{
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly int $paidAmountKurus,
        public readonly string $currency,
    ) {}
}

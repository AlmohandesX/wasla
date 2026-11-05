<?php

namespace App\Services\Payments;

use App\Services\Payments\Drivers\FakeGateway;
use RuntimeException;

/** Picks the driver named by PAYMENT_GATEWAY. Adding a provider means one new case here. */
class GatewayManager
{
    public function driver(?string $name = null): PaymentGateway
    {
        $name = strtolower($name ?? (string) config('wasla.payment_gateway'));

        return match ($name) {
            // Second line of defence behind ProductionGuard.
            'fake' => app()->isProduction()
                ? throw new RuntimeException('The fake payment gateway cannot be used in production.')
                : new FakeGateway,
            default => throw new RuntimeException("Payment gateway [$name] is not available."),
        };
    }
}

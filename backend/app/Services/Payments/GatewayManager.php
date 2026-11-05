<?php

namespace App\Services\Payments;

use App\Services\Payments\Drivers\FakeGateway;
use App\Services\Payments\Drivers\IyzicoGateway;
use App\Services\Payments\Drivers\PaytrGateway;

/** Picks the driver named by PAYMENT_GATEWAY. Adding a provider means one new case here. */
class GatewayManager
{
    public function driver(?string $name = null): PaymentGateway
    {
        $name = strtolower($name ?? (string) config('wasla.payment_gateway'));

        return match ($name) {
            // Second line of defence behind ProductionGuard.
            'fake' => app()->isProduction()
                ? throw new UnknownPaymentGateway('The fake payment gateway cannot be used in production.')
                : new FakeGateway,
            // Built without touching keys; a driver refuses to act when they are missing (PaymentGatewayNotConfigured).
            'iyzico' => new IyzicoGateway,
            'paytr' => new PaytrGateway,
            default => throw new UnknownPaymentGateway("Payment gateway [$name] is not available."),
        };
    }
}

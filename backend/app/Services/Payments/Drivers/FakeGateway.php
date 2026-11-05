<?php

namespace App\Services\Payments\Drivers;

use App\Enums\PaymentStatus;
use App\Services\Payments\CheckoutRequest;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\InvalidCallback;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\ProviderPaymentStatus;
use App\Services\Payments\VerifiedCallback;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Local development and tests only (ProductionGuard and GatewayManager refuse it in production).
 * Callbacks carry an HMAC of the reference; outcomes are scripted with setOutcome().
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function startCheckout(CheckoutRequest $request): CheckoutSession
    {
        $reference = $request->merchantReference;

        return new CheckoutSession(url('/fake-gateway/checkout/'.$reference), $reference);
    }

    public function verifyCallback(Request $request): VerifiedCallback
    {
        $reference = (string) $request->input('reference');
        $signature = (string) $request->input('signature');

        if ($reference === '' || ! hash_equals(self::sign($reference), $signature)) {
            throw new InvalidCallback('Invalid fake gateway signature.');
        }

        return new VerifiedCallback($reference);
    }

    public function fetchStatus(string $reference): ProviderPaymentStatus
    {
        $outcome = Cache::get(self::cacheKey($reference));

        return $outcome === null
            ? new ProviderPaymentStatus(PaymentStatus::Pending, 0, 'TRY')
            : new ProviderPaymentStatus(PaymentStatus::from($outcome['status']), $outcome['amount'], $outcome['currency'] ?? 'TRY');
    }

    public function callbackResponse(): Response
    {
        return new Response('OK', 200);
    }

    public static function sign(string $reference): string
    {
        return hash_hmac('sha256', $reference, (string) config('app.key'));
    }

    /** Scripts what fetchStatus reports for a reference. */
    public static function setOutcome(string $reference, PaymentStatus $status, int $amountKurus, string $currency = 'TRY'): void
    {
        Cache::put(self::cacheKey($reference), ['status' => $status->value, 'amount' => $amountKurus, 'currency' => $currency], now()->addDay());
    }

    private static function cacheKey(string $reference): string
    {
        return 'fake-gateway:'.$reference;
    }
}

<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** One implementation per payment provider. Business code only ever talks to this interface. */
interface PaymentGateway
{
    public function name(): string;

    /** Creates a hosted checkout. No card data ever reaches us. */
    public function startCheckout(CheckoutRequest $request): CheckoutSession;

    /** @throws InvalidCallback when the signature does not verify */
    public function verifyCallback(Request $request): VerifiedCallback;

    /** Asks the provider directly; the callback body alone is never trusted. */
    public function fetchStatus(string $reference): ProviderPaymentStatus;

    /** The body the provider expects as the callback reply. */
    public function callbackResponse(): Response;
}

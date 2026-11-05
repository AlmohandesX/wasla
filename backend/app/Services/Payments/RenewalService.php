<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentUnavailable;
use App\Exceptions\RenewalNoTag;
use App\Exceptions\RenewalPriceUnavailable;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenewalService
{
    public const CURRENCY = 'TRY';

    public const PRICE_SETTING = 'renewal_price_kurus';

    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Opens a hosted checkout and records a pending payment at the price in force right now. It never
     * extends the subscription: only a verified provider callback does.
     */
    public function start(User $user, Tag $tag, BillingDetails $billing, string $buyerIp = '0.0.0.0'): CheckoutSession
    {
        return $this->startWithPayment($user, $tag, $billing, $buyerIp)[0];
    }

    /** @return array{CheckoutSession, Payment} */
    public function startWithPayment(User $user, Tag $tag, BillingDetails $billing, string $buyerIp = '0.0.0.0'): array
    {
        if ($tag->first_activated_at === null || $tag->revoked_at !== null) {
            throw new RenewalNoTag;
        }

        $price = $this->currentPriceKurus();

        try {
            $session = $this->gateway->startCheckout(new CheckoutRequest(
                merchantReference: 'W'.strtoupper(bin2hex(random_bytes(12))),
                amountKurus: $price,
                currency: self::CURRENCY,
                buyerEmail: $user->email,
                buyerIp: $buyerIp,
                locale: $user->preferredLocale(),
                description: 'WASLA '.$tag->serial,
                billing: $billing,
            ));
        } catch (Throwable $e) {
            // Class only: a provider message could echo request data, the national ID included.
            Log::warning('Payment checkout could not be started.', ['provider' => $this->gateway->name(), 'exception' => $e::class]);

            throw new PaymentUnavailable;
        }

        $payment = Payment::create([
            'tag_id' => $tag->id,
            'user_id' => $user->id,
            'provider' => $this->gateway->name(),
            'reference' => $session->reference,
            'amount_kurus' => $price,
            'currency' => self::CURRENCY,
            'status' => PaymentStatus::Pending,
            'billing_name' => $billing->name,
            'billing_address' => $billing->address,
            'billing_city' => $billing->city,
            'billing_national_id' => $billing->nationalId,
            'legal_accepted_at' => now(),
            'legal_text_version' => (string) config('wasla.legal_text_version'),
        ]);

        return [$session, $payment];
    }

    private function currentPriceKurus(): int
    {
        $raw = Setting::value(self::PRICE_SETTING);

        if ($raw === null || ! ctype_digit($raw) || (int) $raw <= 0) {
            throw new RenewalPriceUnavailable;
        }

        return (int) $raw;
    }
}

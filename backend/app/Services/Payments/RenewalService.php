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
use App\Services\Tags\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenewalService
{
    public const CURRENCY = 'TRY';

    public const PRICE_SETTING = 'renewal_price_kurus';

    /** amount_kurus is an unsigned 32-bit column. */
    public const MAX_KURUS = 4294967295;

    private ?Payment $lastPayment = null;

    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Records a pending payment at the price in force right now, then opens a hosted checkout for it.
     * The row exists before the provider session does, so a paid checkout always has a record. It never
     * extends the subscription: only a verified provider callback does.
     */
    public function start(User $user, Tag $tag, BillingDetails $billing, string $buyerIp): CheckoutSession
    {
        if ($tag->first_activated_at === null || $tag->revoked_at !== null) {
            throw new RenewalNoTag;
        }

        $price = $this->currentPriceKurus();
        $merchantReference = 'W'.strtoupper(bin2hex(random_bytes(12)));

        $payment = Payment::create([
            'tag_id' => $tag->id,
            'user_id' => $user->id,
            'provider' => $this->gateway->name(),
            'reference' => $merchantReference,
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

        try {
            $session = $this->gateway->startCheckout(new CheckoutRequest(
                merchantReference: $merchantReference,
                amountKurus: $price,
                currency: self::CURRENCY,
                buyerEmail: $user->email,
                buyerIp: $buyerIp,
                locale: $user->preferredLocale(),
                description: 'WASLA '.$tag->serial,
                billing: $billing,
            ));

            if ($session->reference !== $merchantReference) {
                $payment->update(['reference' => $session->reference]);
            }
        } catch (Throwable $e) {
            // Class only: a provider message could echo request data, the national ID included.
            Log::warning('Payment checkout could not be started.', ['provider' => $this->gateway->name(), 'exception' => $e::class]);
            $payment->update([
                'status' => PaymentStatus::Failed,
                'billing_name' => null,
                'billing_address' => null,
                'billing_city' => null,
                'billing_national_id' => null,
            ]);

            throw new PaymentUnavailable;
        }

        $this->lastPayment = $payment;

        return $session;
    }

    /**
     * The only code path that extends a subscription. Order matters: verify the signature, find our own record,
     * ask the provider for the real status (the callback body alone is never trusted), then settle under a row lock.
     *
     * @throws InvalidCallback when the signature does not verify; nothing is read or changed before that
     */
    public function handleCallback(PaymentGateway $gateway, Request $request): void
    {
        try {
            $callback = $gateway->verifyCallback($request);
        } catch (InvalidCallback $e) {
            Log::warning('Payment callback refused: invalid signature.', ['provider' => $gateway->name()]);

            throw $e;
        }

        $payment = Payment::query()
            ->where('reference', $callback->reference)
            ->where('provider', $gateway->name())
            ->first();

        if ($payment === null) {
            Log::warning('Payment callback for an unknown reference.', [
                'provider' => $gateway->name(),
                'reference' => $callback->reference,
            ]);

            return;
        }

        $remote = $gateway->fetchStatus($callback->reference);

        DB::transaction(function () use ($payment, $remote, $gateway) {
            // Concurrent deliveries queue here; the second one sees the first one's result.
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            // Paid and refunded are final here. A failed payment may still turn paid: the provider has taken
            // the money, and a late success beats an earlier failure.
            if (! in_array($locked->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
                return;
            }

            if ($remote->status === PaymentStatus::Failed) {
                if ($locked->status === PaymentStatus::Pending) {
                    $locked->update(['status' => PaymentStatus::Failed]);
                }

                return;
            }

            if ($remote->status !== PaymentStatus::Paid) {
                return;
            }

            $reason = match (true) {
                $remote->paidAmountKurus !== $locked->amount_kurus => 'amount_mismatch',
                $remote->currency !== $locked->currency => 'currency_mismatch',
                default => null,
            };

            if ($reason !== null) {
                // Money moved but not as asked: leave it for an admin. Ids only, never billing data.
                $locked->update(['flagged_at' => $locked->flagged_at ?? now(), 'flag_reason' => $reason]);
                Log::warning('Payment callback does not match the recorded payment.', [
                    'provider' => $gateway->name(),
                    'payment_id' => $locked->id,
                    'reason' => $reason,
                ]);

                return;
            }

            $locked->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
            app(SubscriptionService::class)->extendOneYear($locked->tag()->firstOrFail());
        });
    }

    /** The payment recorded by the last successful start(). */
    public function lastPayment(): ?Payment
    {
        return $this->lastPayment;
    }

    private function currentPriceKurus(): int
    {
        $raw = Setting::value(self::PRICE_SETTING);

        if ($raw === null || ! ctype_digit($raw) || (int) $raw <= 0) {
            throw new RenewalPriceUnavailable;
        }

        if (strlen(ltrim($raw, '0')) > 10 || (int) $raw > self::MAX_KURUS) {
            throw new PaymentUnavailable;
        }

        return (int) $raw;
    }
}

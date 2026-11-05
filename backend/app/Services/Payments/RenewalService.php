<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\AdminActionException;
use App\Exceptions\PaymentUnavailable;
use App\Exceptions\RenewalNoTag;
use App\Exceptions\RenewalPriceUnavailable;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Services\Concerns\AuditsAdminActions;
use App\Services\Tags\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenewalService
{
    use AuditsAdminActions;

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
                buyerId: (string) $user->id,
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

        try {
            $remote = $gateway->fetchStatus($callback->reference);
        } catch (Throwable $e) {
            // A verified callback for a payment we hold, unsettled for now. Ids and class only, never the message.
            Log::error('Payment status check failed.', [
                'provider' => $gateway->name(),
                'payment_id' => $payment->id,
                'exception' => $e::class,
            ]);

            throw $e;
        }

        try {
            $this->settle($payment, $remote, $gateway);
        } catch (Throwable $e) {
            $this->recordSettlementFailure($payment, $gateway, $e);

            throw $e;
        }
    }

    private function settle(Payment $payment, ProviderPaymentStatus $remote, PaymentGateway $gateway): void
    {
        DB::transaction(function () use ($payment, $remote, $gateway) {
            // Concurrent deliveries queue here; the second one sees the first one's result.
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            // Paid and refunded are final here. A failed payment may still turn paid: the provider has taken
            // the money, and a late success beats an earlier failure.
            if (! in_array($locked->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
                return;
            }

            if ($remote->status === PaymentStatus::Refunded) {
                // Money came and went before we settled, so nothing extends. Leave it for an admin. Ids only.
                $this->flag($locked, 'refunded_at_provider', $gateway);
                Log::warning('Payment callback for a payment the provider has refunded.', [
                    'provider' => $gateway->name(),
                    'payment_id' => $locked->id,
                ]);

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
                $this->flag($locked, $reason, $gateway);
                Log::warning('Payment callback does not match the recorded payment.', [
                    'provider' => $gateway->name(),
                    'payment_id' => $locked->id,
                    'reason' => $reason,
                ]);

                return;
            }

            $locked->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

            $tag = $this->liveTag($locked->tag_id);
            if ($tag->isRevoked()) {
                // Paid, but the tag was revoked with no replacement: nothing to extend. Leave it for an admin. Ids only.
                $this->flag($locked, 'paid_on_revoked_tag', $gateway);
                Log::warning('Payment settled on a revoked tag with no replacement.', [
                    'provider' => $gateway->name(),
                    'payment_id' => $locked->id,
                ]);

                return;
            }

            app(SubscriptionService::class)->extendOneYear($tag);
            $locked->update(['extended_tag_id' => $tag->id]);

            if ($tag->id !== $locked->tag_id) {
                $this->flag($locked, 'settled_on_replacement', $gateway);
            }
        });
    }

    /** Same as refund(), without telling which tag was shortened. */
    public function markRefunded(Payment $payment, User $admin, string $reason): void
    {
        $this->refund($payment, $admin, $reason);
    }

    /**
     * Records a refund decision in WASLA only; the money is returned in the provider's panel. Shortens the tag that
     * settlement extended, followed through any replacement to the tag that is live now, and nothing when settlement
     * extended nothing or that tag has since been revoked with no replacement.
     *
     * @return Tag|null the tag that lost a year
     *
     * @throws AdminActionException when the reason is empty or the payment is not Paid (checked under the row lock)
     */
    public function refund(Payment $payment, User $admin, string $reason): ?Tag
    {
        $reason = $this->requireReason($reason);

        $shortened = DB::transaction(function () use ($payment, $admin, $reason) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status !== PaymentStatus::Paid) {
                throw AdminActionException::because('payment_not_refundable');
            }

            $locked->update(['status' => PaymentStatus::Refunded, 'refunded_at' => now()]);

            $tag = null;
            if ($locked->extended_tag_id !== null) {
                $live = $this->liveTag($locked->extended_tag_id);
                if (! $live->isRevoked() && $live->expires_at !== null) {
                    app(SubscriptionService::class)->shortenOneYear($live);
                    $tag = $live;
                }
            }

            $this->audit($admin, 'payment.refund', 'payment', $locked->id, $reason, [
                'extended_tag_id' => $locked->extended_tag_id,
                'shortened_tag_id' => $tag?->id,
            ]);

            return $tag;
        });

        $payment->refresh();

        return $shortened;
    }

    /** Marks a flag as looked at. The flag itself stays as first written. */
    public function markFlagReviewed(Payment $payment, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        DB::transaction(function () use ($payment, $admin, $reason) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->flag_reason === null || $locked->flag_reviewed_at !== null) {
                throw AdminActionException::because('flag_not_reviewable');
            }

            $locked->update(['flag_reviewed_at' => now(), 'flag_reviewed_by' => $admin->id]);
            $this->audit($admin, 'payment.flag_review', 'payment', $locked->id, $reason, ['flag_reason' => $locked->flag_reason]);
        });
    }

    /** The first flag wins: a later one is logged, never written over it. */
    private function flag(Payment $payment, string $reason, PaymentGateway $gateway): void
    {
        if ($payment->flag_reason !== null) {
            Log::warning('Payment already flagged; later flag not recorded.', [
                'provider' => $gateway->name(),
                'payment_id' => $payment->id,
                'existing_flag' => $payment->flag_reason,
                'skipped_flag' => $reason,
            ]);

            return;
        }

        $payment->update(['flagged_at' => $payment->flagged_at ?? now(), 'flag_reason' => $reason]);
    }

    /** Locks the paid tag and, if an admin replaced it, follows the replacement chain to the tag that is live now. */
    private function liveTag(int $tagId): Tag
    {
        $tag = Tag::query()->lockForUpdate()->findOrFail($tagId);

        for ($hops = 0; $tag->isRevoked() && $tag->replaced_by_tag_id !== null && $hops < 10; $hops++) {
            $tag = Tag::query()->lockForUpdate()->findOrFail($tag->replaced_by_tag_id);
        }

        return $tag;
    }

    /**
     * The settlement rolled back, so a payment the provider may have taken money for is still pending. Leave a trail
     * an admin can act on: ids and location only, never the message (it could echo request or billing data).
     */
    private function recordSettlementFailure(Payment $payment, PaymentGateway $gateway, Throwable $e): void
    {
        Log::error('Payment settlement failed.', [
            'provider' => $gateway->name(),
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        try {
            Payment::query()->whereKey($payment->id)->whereNull('flagged_at')
                ->update(['flagged_at' => now(), 'flag_reason' => 'settlement_failed']);
        } catch (Throwable) {
            // The log line above is the fallback if even this write fails.
        }
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

<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Tag;
use App\Services\Payments\Drivers\FakeGateway;
use App\Services\Tags\TagAdminService;
use Illuminate\Support\Facades\Log;

function paymentForTag(Tag $tag, int $userId): Payment
{
    return Payment::create([
        'tag_id' => $tag->id,
        'user_id' => $userId,
        'provider' => 'fake',
        'reference' => 'W'.strtoupper(bin2hex(random_bytes(8))),
        'amount_kurus' => 79900,
        'currency' => 'TRY',
        'status' => PaymentStatus::Pending,
        'legal_accepted_at' => now(),
        'legal_text_version' => '1',
    ]);
}

function payCallback(Payment $payment): void
{
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);
    test()->post('/payments/callback/fake', [
        'reference' => $payment->reference,
        'signature' => FakeGateway::sign($payment->reference),
    ])->assertOk();
}

it('settles a payment that was pending when the tag was replaced onto the replacement, once', function () {
    [$old, , $user] = adminClaimedTag();
    $payment = paymentForTag($old, $user->id);
    $new = tagFixture();
    app(TagAdminService::class)->replace($old, $new, adminUser(), 'damaged');
    $oldExpiry = $old->fresh()->expires_at;

    payCallback($payment);
    $extended = $new->fresh()->expires_at;
    payCallback($payment); // replay

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->flag_reason)->toBe('settled_on_replacement')
        ->and($payment->flagged_at)->not->toBeNull()
        ->and($extended->equalTo($oldExpiry->copy()->addYear()))->toBeTrue()
        ->and($new->fresh()->expires_at->equalTo($extended))->toBeTrue()
        ->and($old->fresh()->expires_at->equalTo($oldExpiry))->toBeTrue();
});

it('keeps a payment on a revoked tag with no replacement paid, extends nothing and flags it', function () {
    [$tag, , $user] = adminClaimedTag();
    $payment = paymentForTag($tag, $user->id);
    app(TagAdminService::class)->revoke($tag, adminUser(), 'stolen');
    $expiry = $tag->fresh()->expires_at;
    Log::spy();

    payCallback($payment);
    payCallback($payment); // replay

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->flag_reason)->toBe('paid_on_revoked_tag')
        ->and($tag->fresh()->expires_at->equalTo($expiry))->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => ($c['payment_id'] ?? null) === $payment->id)->once();
});

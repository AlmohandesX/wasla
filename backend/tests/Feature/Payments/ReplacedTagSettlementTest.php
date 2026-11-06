<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Tag;
use App\Services\Payments\Drivers\FakeGateway;
use App\Services\Tags\TagAdminService;
use App\Services\Tags\TagClaimer;
use App\Services\Tags\TagTransfer;
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

it('follows a two-hop replacement chain to the live tag', function () {
    [$first, , $user] = adminClaimedTag();
    $payment = paymentForTag($first, $user->id);
    $second = tagFixture();
    $third = tagFixture();
    $admin = adminUser();
    app(TagAdminService::class)->replace($first, $second, $admin, 'one');
    app(TagAdminService::class)->replace($second, $third, $admin, 'two');
    $expiry = $third->fresh()->expires_at;

    payCallback($payment);

    expect($third->fresh()->expires_at->equalTo($expiry->copy()->addYear()))->toBeTrue()
        ->and($second->fresh()->expires_at->equalTo($expiry))->toBeTrue()
        ->and($payment->fresh()->flag_reason)->toBe('settled_on_replacement');
});

it('extends nothing when the replacement was itself revoked', function () {
    [$old, , $user] = adminClaimedTag();
    $payment = paymentForTag($old, $user->id);
    $new = tagFixture();
    $admin = adminUser();
    app(TagAdminService::class)->replace($old, $new, $admin, 'damaged');
    app(TagAdminService::class)->revoke($new, $admin, 'stolen');
    $expiry = $new->fresh()->expires_at;

    payCallback($payment);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->flag_reason)->toBe('paid_on_revoked_tag')
        ->and($new->fresh()->expires_at->equalTo($expiry))->toBeTrue();
});

it('never replaces an earlier flag, and logs the one it could not record', function () {
    [$old, , $user] = adminClaimedTag();
    $payment = paymentForTag($old, $user->id);
    app(TagAdminService::class)->replace($old, tagFixture(), adminUser(), 'damaged');

    // First delivery: wrong amount, flagged and left pending.
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 1);
    test()->post('/payments/callback/fake', ['reference' => $payment->reference, 'signature' => FakeGateway::sign($payment->reference)])->assertOk();
    expect($payment->fresh()->flag_reason)->toBe('amount_mismatch');

    Log::spy();
    payCallback($payment); // correct amount arrives later

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)->and($payment->flag_reason)->toBe('amount_mismatch');
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => ($c['payment_id'] ?? null) === $payment->id
        && ($c['skipped_flag'] ?? null) === 'settled_on_replacement')->once();
});

it('flags a payment that settles after the tag passed to someone else, and still extends the tag', function () {
    [$tag, , $payer] = adminClaimedTag();
    $payment = paymentForTag($tag, $payer->id);
    app(TagAdminService::class)->forceRelease($tag, adminUser(), 'sold car');
    app(TagClaimer::class)->claim(owner(), $tag->token, carAttrs());
    $expiry = $tag->fresh()->expires_at;

    payCallback($payment);

    expect($payment->fresh()->flag_reason)->toBe('settled_after_transfer')
        ->and($tag->fresh()->expires_at->equalTo($expiry->copy()->addYear()))->toBeTrue();
});

it('flags a payment that settles on a tag left unlinked since checkout', function () {
    [$tag, $car, $payer] = adminClaimedTag();
    $payment = paymentForTag($tag, $payer->id);
    app(TagTransfer::class)->unlink($tag);

    payCallback($payment);

    expect($payment->fresh()->flag_reason)->toBe('settled_after_transfer');
});

it('does not flag a payment that settles while the payer still holds the tag', function () {
    [$tag, , $payer] = adminClaimedTag();
    $payment = paymentForTag($tag, $payer->id);

    payCallback($payment);

    expect($payment->fresh()->flag_reason)->toBeNull();
});

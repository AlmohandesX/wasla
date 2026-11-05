<?php

use App\Enums\PaymentStatus;
use App\Models\Car;
use App\Models\Payment;
use App\Models\Tag;
use App\Services\Payments\Drivers\FakeGateway;
use App\Services\Tags\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

function pendingPayment(?Tag $tag = null, int $amount = 79900, ?string $reference = null): Payment
{
    $user = owner(['email_verified_at' => now()]);
    $tag ??= linkedCar($user, now()->addMonth())->tag;

    return Payment::create([
        'tag_id' => $tag->id,
        'user_id' => $user->id,
        'provider' => 'fake',
        'reference' => $reference ?? 'W'.strtoupper(bin2hex(random_bytes(8))),
        'amount_kurus' => $amount,
        'currency' => 'TRY',
        'status' => PaymentStatus::Pending,
        'billing_name' => 'Ayse Yilmaz',
        'billing_national_id' => '10000000146',
        'legal_accepted_at' => now(),
        'legal_text_version' => '1',
    ]);
}

function postCallback(string $reference, ?string $signature = null, string $provider = 'fake')
{
    return test()->post("/payments/callback/$provider", [
        'reference' => $reference,
        'signature' => $signature ?? FakeGateway::sign($reference),
    ]);
}

function expiryOf(Payment $payment): string
{
    return Tag::find($payment->tag_id)->expires_at->toDateTimeString();
}

it('extends once on a verified paid callback', function () {
    $payment = pendingPayment();
    $before = Tag::find($payment->tag_id)->expires_at;
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);

    postCallback($payment->reference)->assertOk()->assertSee('OK');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and(Tag::find($payment->tag_id)->expires_at->toDateTimeString())
        ->toBe($before->copy()->addYear()->toDateTimeString());
});

it('extends once when the same callback arrives twice', function () {
    $payment = pendingPayment();
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);

    postCallback($payment->reference)->assertOk();
    $after = expiryOf($payment);
    postCallback($payment->reference)->assertOk();

    expect(expiryOf($payment))->toBe($after);
});

it('extends once on concurrent callbacks', function () {
    // One process cannot race itself, so this asserts the mechanism: the payment row is read under
    // lockForUpdate inside a transaction, and a second delivery after the first is a no-op.
    $payment = pendingPayment();
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });

    postCallback($payment->reference)->assertOk();

    $locked = array_filter($queries, fn ($sql) => str_contains($sql, 'from `payments`') && str_contains($sql, 'for update'));
    expect($locked)->not->toBeEmpty();

    $after = expiryOf($payment);
    postCallback($payment->reference)->assertOk();
    expect(expiryOf($payment))->toBe($after);
});

it('rejects a bad signature and changes nothing', function () {
    $payment = pendingPayment();
    $before = expiryOf($payment);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);

    postCallback($payment->reference, 'forged')->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(expiryOf($payment))->toBe($before);
});

it('answers a bad signature the same whether or not the reference exists', function () {
    $payment = pendingPayment();

    $known = postCallback($payment->reference, 'forged');
    $unknown = postCallback('WDOESNOTEXIST', 'forged');

    expect($known->status())->toBe($unknown->status())
        ->and($known->getContent())->toBe($unknown->getContent());
});

it('does not extend when the provider status re-check says unpaid', function () {
    $payment = pendingPayment();
    $before = expiryOf($payment);
    // A correctly signed callback, but the provider itself reports it is not paid.
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Pending, 0);

    postCallback($payment->reference)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(expiryOf($payment))->toBe($before);
});

it('does not extend on an amount mismatch and flags it', function () {
    $payment = pendingPayment();
    $before = expiryOf($payment);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 100);
    Log::spy();

    postCallback($payment->reference)->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->flagged_at)->not->toBeNull()
        ->and($payment->flag_reason)->toBe('amount_mismatch')
        ->and(expiryOf($payment))->toBe($before);
    Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
        return ! str_contains(json_encode($context), 'Ayse') && ! str_contains(json_encode($context), '10000000146');
    })->atLeast()->once();
});

it('does not extend on a currency mismatch and flags it', function () {
    $payment = pendingPayment();
    $before = expiryOf($payment);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900, 'USD');

    postCallback($payment->reference)->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->flag_reason)->toBe('currency_mismatch')
        ->and(expiryOf($payment))->toBe($before);
});

it('does not extend from the return page', function () {
    $payment = pendingPayment();
    $before = expiryOf($payment);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);

    $this->get('/payments/return?reference='.$payment->reference.'&status=success')->assertOk();
    $this->post('/payments/return', ['reference' => $payment->reference])->assertStatus(405);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(expiryOf($payment))->toBe($before);
});

it('extends the tag after it changed owner', function () {
    $payment = pendingPayment();
    $before = Tag::find($payment->tag_id)->expires_at;
    $newCar = Car::create(array_merge(carAttrs(['plate' => '06 XYZ 789']), ['user_id' => owner(['email_verified_at' => now()])->id]));
    Tag::find($payment->tag_id)->update(['car_id' => $newCar->id]);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);

    postCallback($payment->reference)->assertOk();

    expect(Tag::find($payment->tag_id)->expires_at->toDateTimeString())
        ->toBe($before->copy()->addYear()->toDateTimeString())
        ->and($payment->fresh()->user_id)->toBe($payment->user_id);
});

it('ignores a late failure callback on a paid payment', function () {
    $payment = pendingPayment();
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);
    postCallback($payment->reference)->assertOk();
    $after = expiryOf($payment);

    FakeGateway::setOutcome($payment->reference, PaymentStatus::Failed, 0);
    postCallback($payment->reference)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(expiryOf($payment))->toBe($after);
});

it('marks a pending payment failed on a verified failure', function () {
    $payment = pendingPayment();
    $before = expiryOf($payment);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Failed, 0);

    postCallback($payment->reference)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and(expiryOf($payment))->toBe($before);
});

it('pays and extends once when a verified success follows an earlier failure', function () {
    $payment = pendingPayment();
    $before = Tag::find($payment->tag_id)->expires_at;
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Failed, 0);
    postCallback($payment->reference)->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);

    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);
    postCallback($payment->reference)->assertOk();
    postCallback($payment->reference)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(expiryOf($payment))->toBe($before->copy()->addYear()->toDateTimeString());
});

it('never moves a refunded payment', function () {
    $payment = pendingPayment();
    $payment->update(['status' => PaymentStatus::Refunded]);
    $before = expiryOf($payment);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);

    postCallback($payment->reference)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and(expiryOf($payment))->toBe($before);
});

it('ignores a payment that belongs to another provider', function () {
    $payment = pendingPayment();
    $payment->update(['provider' => 'iyzico']);
    $before = expiryOf($payment);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);

    postCallback($payment->reference)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(expiryOf($payment))->toBe($before);
});

it('ignores an unknown reference and logs it without a signature', function () {
    $signature = FakeGateway::sign('WUNKNOWN');
    Log::spy();

    postCallback('WUNKNOWN')->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($signature) {
        return str_contains($message, 'unknown') && ! str_contains(json_encode($context), $signature);
    })->once();
    expect(Payment::count())->toBe(0);
});

it('returns 404 for an unknown provider', function () {
    $payment = pendingPayment();
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);

    postCallback($payment->reference, null, 'nope')->assertNotFound();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('serves the callback without a session, cookies or CSRF, on a named limiter', function () {
    $route = Route::getRoutes()->getByName('payments.callback');

    expect($route->gatherMiddleware())->toContain('throttle:payment-callback')
        ->and(implode(',', $route->excludedMiddleware()))->toContain('PreventRequestForgery');

    $payment = pendingPayment();
    $response = postCallback($payment->reference, 'forged');
    expect($response->headers->getCookies())->toBeEmpty();
});

it('shows a state-free return page', function () {
    $this->get('/payments/return')->assertOk()->assertSee(__('wasla.payment_return_title'));
});

describe('fake gateway checkout page', function () {
    it('lets the developer pay, which extends through the signed callback', function () {
        $payment = pendingPayment();
        $before = Tag::find($payment->tag_id)->expires_at;

        $this->get('/fake-gateway/checkout/'.$payment->reference)->assertOk()
            ->assertSee('/fake-gateway/checkout/'.$payment->reference.'/pay', false)
            ->assertSee('/fake-gateway/checkout/'.$payment->reference.'/fail', false)
            ->assertDontSee('<script', false);

        $this->post('/fake-gateway/checkout/'.$payment->reference.'/pay')->assertRedirect('/payments/return');

        expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and(expiryOf($payment))->toBe($before->copy()->addYear()->toDateTimeString());
    });

    it('lets the developer fail the payment', function () {
        $payment = pendingPayment();
        $before = expiryOf($payment);

        $this->post('/fake-gateway/checkout/'.$payment->reference.'/fail')->assertRedirect('/payments/return');

        expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)->and(expiryOf($payment))->toBe($before);
    });

    it('404s for an unknown reference or a payment of another provider', function () {
        $payment = pendingPayment();
        $payment->update(['provider' => 'iyzico']);

        $this->get('/fake-gateway/checkout/WNOPE')->assertNotFound();
        $this->get('/fake-gateway/checkout/'.$payment->reference)->assertNotFound();
        $this->post('/fake-gateway/checkout/'.$payment->reference.'/pay')->assertNotFound();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    });

    it('404s when the configured gateway is not fake', function () {
        $payment = pendingPayment();
        config(['wasla.payment_gateway' => 'iyzico']);

        $this->get('/fake-gateway/checkout/'.$payment->reference)->assertNotFound();
        $this->post('/fake-gateway/checkout/'.$payment->reference.'/pay')->assertNotFound();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    });

    it('does not register the route at all under another gateway', function () {
        // Routes are registered at boot from the environment, so list them in a separate boot per gateway.
        $routes = function (string $gateway): string {
            $process = proc_open(
                [PHP_BINARY, 'artisan', 'route:list', '--path=fake-gateway', '--json'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                array_merge(getenv(), ['PAYMENT_GATEWAY' => $gateway, 'APP_ENV' => 'local']),
            );
            $out = stream_get_contents($pipes[1]);
            proc_close($process);

            return $out;
        };

        expect($routes('fake'))->toContain('fake-gateway.show')
            ->and($routes('iyzico'))->not->toContain('fake-gateway');
    });
});

it('flags a paid payment whose settlement failed and logs where, so it can be recovered', function () {
    $payment = pendingPayment();
    $before = expiryOf($payment);
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, 79900);
    $this->mock(SubscriptionService::class)->shouldReceive('extendOneYear')->andThrow(new RuntimeException('boom Ayse 10000000146'));
    Log::spy();

    postCallback($payment->reference)->assertStatus(503);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->flagged_at)->not->toBeNull()
        ->and($payment->flag_reason)->toBe('settlement_failed')
        ->and(expiryOf($payment))->toBe($before);
    Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use ($payment) {
        $json = json_encode($context);

        return ($context['payment_id'] ?? null) === $payment->id
            && ($context['exception'] ?? null) === RuntimeException::class
            && isset($context['file'], $context['line'])
            && ! str_contains($json, 'Ayse') && ! str_contains($json, '10000000146');
    })->once();
});

it('treats array input on the fake gateway as an invalid callback, not a 500', function () {
    $this->post('/payments/callback/fake', ['reference' => ['x'], 'signature' => 'y'])->assertStatus(400);
    $this->post('/payments/callback/fake', ['reference' => 'x', 'signature' => ['y']])->assertStatus(400);
});

it('still extends but flags a second payment paid within 24 hours for the same tag', function () {
    $first = pendingPayment();
    $tag = Tag::find($first->tag_id);
    FakeGateway::setOutcome($first->reference, PaymentStatus::Paid, 79900);
    postCallback($first->reference)->assertOk();
    $afterFirst = expiryOf($first);

    $second = pendingPayment($tag);
    FakeGateway::setOutcome($second->reference, PaymentStatus::Paid, 79900);
    Log::spy();
    postCallback($second->reference)->assertOk();

    $second->refresh();
    expect($second->status)->toBe(PaymentStatus::Paid)
        ->and($second->flag_reason)->toBe('possible_duplicate')
        ->and(expiryOf($second))->not->toBe($afterFirst)
        ->and($first->refresh()->flag_reason)->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => ($c['payment_id'] ?? null) === $second->id && str_contains($m, 'duplicate'))->once();
});

it('does not flag a second payment paid more than 24 hours after the first', function () {
    $first = pendingPayment();
    $tag = Tag::find($first->tag_id);
    FakeGateway::setOutcome($first->reference, PaymentStatus::Paid, 79900);
    postCallback($first->reference)->assertOk();
    Payment::query()->whereKey($first->id)->update(['paid_at' => now()->subHours(25)]);

    $second = pendingPayment($tag);
    FakeGateway::setOutcome($second->reference, PaymentStatus::Paid, 79900);
    postCallback($second->reference)->assertOk();

    expect($second->refresh()->flag_reason)->toBeNull();
});

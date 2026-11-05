<?php

use App\Enums\PaymentStatus;
use App\Models\Car;
use App\Models\Payment;
use App\Models\Setting;
use App\Rules\TcKimlik;
use App\Services\Payments\BillingDetails;
use App\Services\Payments\CheckoutRequest;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\Drivers\FakeGateway;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\InvalidCallback;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

const TCKN = '10000000146';

function renewalBody(array $overrides = []): array
{
    return array_merge([
        'billing_name' => 'Ayse Yilmaz',
        'billing_address' => 'Moda Cad. No 5 Kadikoy',
        'billing_city' => 'Istanbul',
        'billing_national_id' => TCKN,
        'legal_accepted' => true,
    ], $overrides);
}

function setRenewalPrice(?int $kurus = 79900): void
{
    Setting::query()->where('key', 'renewal_price_kurus')->delete();
    if ($kurus !== null) {
        Setting::create(['key' => 'renewal_price_kurus', 'value' => (string) $kurus]);
    }
}

function startRenewal($user, $car, array $overrides = [])
{
    // The start limiter is tight by design; tests that are not about it begin each call with a clean slate.
    Cache::flush();

    return test()->withHeaders(bearer($user))->postJson("/api/v1/cars/{$car->id}/renewals", renewalBody($overrides));
}

function verifiedOwner()
{
    return owner(['email_verified_at' => now()]);
}

beforeEach(fn () => setRenewalPrice());

it('creates a pending payment at the current price and returns the checkout URL', function () {
    $user = verifiedOwner();
    $car = linkedCar($user);

    $res = startRenewal($user, $car)->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount_kurus', 79900)
        ->assertJsonPath('data.currency', 'TRY');

    $payment = Payment::first();
    expect($res->json('data.redirect_url'))->toStartWith('http')->toContain($payment->reference)
        ->and($payment->provider)->toBe('fake')
        ->and($payment->tag_id)->toBe($car->tag->id)
        ->and($payment->user_id)->toBe($user->id)
        ->and($payment->amount_kurus)->toBe(79900)
        ->and($payment->status->value)->toBe('pending')
        ->and($payment->legal_accepted_at)->not->toBeNull()
        ->and($payment->legal_text_version)->toBe(config('wasla.legal_text_version'))
        ->and($payment->billing_name)->toBe('Ayse Yilmaz');
});

it('uses the price at start even if it changes later', function () {
    $user = verifiedOwner();
    $car = linkedCar($user);
    startRenewal($user, $car)->assertCreated();

    setRenewalPrice(99900);

    expect(Payment::first()->amount_kurus)->toBe(79900);
    startRenewal($user, $car)->assertCreated()->assertJsonPath('data.amount_kurus', 99900);
});

it('ignores a client supplied amount, provider or status', function () {
    $user = verifiedOwner();
    startRenewal($user, linkedCar($user), ['amount_kurus' => 1, 'provider' => 'x', 'status' => 'paid'])->assertCreated();

    $payment = Payment::first();
    expect($payment->amount_kurus)->toBe(79900)->and($payment->provider)->toBe('fake')
        ->and($payment->status)->toBe(PaymentStatus::Pending);
});

it('requires billing details and legal acceptance', function () {
    $user = verifiedOwner();
    $car = linkedCar($user);

    foreach (['billing_name', 'billing_address', 'billing_city', 'billing_national_id', 'legal_accepted'] as $field) {
        startRenewal($user, $car, [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    startRenewal($user, $car, ['legal_accepted' => false])->assertUnprocessable()->assertJsonValidationErrors('legal_accepted');

    expect(Payment::count())->toBe(0);
});

it('stores the national ID encrypted', function () {
    $user = verifiedOwner();
    startRenewal($user, linkedCar($user))->assertCreated();

    $row = DB::table('payments')->first();
    expect($row->billing_national_id)->not->toBe(TCKN)->not->toContain(substr(TCKN, 0, 4));
    foreach (['billing_name', 'billing_address', 'billing_city'] as $col) {
        expect($row->$col)->not->toContain('Yilmaz')->not->toContain('Kadikoy')->not->toContain('Istanbul');
    }
    expect(Payment::first()->billing_national_id)->toBe(TCKN)
        ->and(Payment::first()->toArray())->not->toHaveKey('billing_national_id');
});

it('rejects an invalid national ID', function (string $id) {
    $user = verifiedOwner();
    startRenewal($user, linkedCar($user), ['billing_national_id' => $id])
        ->assertUnprocessable()->assertJsonValidationErrors('billing_national_id');
    expect(Payment::count())->toBe(0);
})->with([
    'too short' => '1000000014',
    'too long' => '100000001460',
    'letters' => '1000000014a',
    'bad d10' => '10000000156',
    'bad d11' => '10000000147',
    'leading zero' => '01234567890',
    'foreign style id' => '99000000000',
]);

it('accepts well known valid TC Kimlik numbers', function (string $id) {
    expect(Validator::make(['id' => $id], ['id' => [new TcKimlik]])->passes())->toBeTrue();
})->with(['10000000146', '11111111110']);

it('never writes the national ID to logs or API responses', function () {
    Log::spy();
    $user = verifiedOwner();
    $car = linkedCar($user);

    $res = startRenewal($user, $car)->assertCreated();
    $payment = Payment::first();
    $show = test()->withHeaders(bearer($user))->getJson("/api/v1/renewals/{$payment->id}")->assertOk();
    $bad = startRenewal($user, $car, ['billing_national_id' => '12345678901'])->assertUnprocessable();

    foreach ([$res, $show, $bad] as $r) {
        expect($r->getContent())->not->toContain(TCKN)->not->toContain('12345678901')->not->toContain('national_id":"');
    }
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');

    $billing = new BillingDetails('A B', 'addr', 'city', TCKN);
    expect(print_r($billing, true).json_encode($billing).json_encode(["b" => $billing]))->not->toContain(TCKN);
});

it('does not leak the national ID when the gateway fails', function () {
    $this->app->instance(PaymentGateway::class, new class extends FakeGateway
    {
        public function startCheckout(CheckoutRequest $request): CheckoutSession
        {
            throw new RuntimeException('provider down for '.$request->billing->nationalId);
        }
    });
    Log::spy();
    $user = verifiedOwner();

    $res = startRenewal($user, linkedCar($user))->assertStatus(502)->assertJsonPath('code', 'payment_unavailable');

    expect($res->getContent())->not->toContain(TCKN)->and(Payment::count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => ! str_contains(json_encode([$msg, $ctx]), TCKN));
});

it('keeps the national ID out of flashed input', function () {
    $handler = app(Illuminate\Foundation\Exceptions\Handler::class);
    $ref = new ReflectionProperty($handler, 'dontFlash');
    expect($ref->getValue($handler))->toContain('billing_national_id');
});

it('forbids renewing another user car', function () {
    $user = verifiedOwner();
    $car = linkedCar(verifiedOwner());

    startRenewal($user, $car)->assertForbidden();
    expect(Payment::count())->toBe(0);
});

it('shows a payment only to its owner and only its status', function () {
    $user = verifiedOwner();
    $other = verifiedOwner();
    startRenewal($user, linkedCar($user))->assertCreated();
    $payment = Payment::first();

    test()->withHeaders(bearer($user))->getJson("/api/v1/renewals/{$payment->id}")
        ->assertOk()->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.billing_name')->assertJsonMissingPath('data.billing_address')
        ->assertJsonMissingPath('data.reference');

    app('auth')->forgetGuards();
    test()->withHeaders(bearer($other))->getJson("/api/v1/renewals/{$payment->id}")->assertForbidden();
});

it('is allowed on an expired car', function () {
    $user = verifiedOwner();

    startRenewal($user, linkedCar($user, now()->subDays(10)))->assertCreated();
});

it('refuses a car with no tag', function () {
    $user = verifiedOwner();
    $car = Car::create(array_merge(carAttrs(), ['user_id' => $user->id]));

    startRenewal($user, $car)->assertStatus(422)->assertJsonPath('code', 'renewal_no_tag')
        ->assertJsonPath('message', __('wasla.error_renewal_no_tag'));
    expect(Payment::count())->toBe(0);
});

it('fails to start when no price is set', function (?int $price) {
    setRenewalPrice($price);
    $user = verifiedOwner();

    startRenewal($user, linkedCar($user))->assertStatus(503)->assertJsonPath('code', 'renewal_price_unavailable');
    expect(Payment::count())->toBe(0);
})->with([[null], [0]]);

it('rate limits renewal starts', function () {
    $user = verifiedOwner();
    $car = linkedCar($user);
    $last = null;
    for ($i = 0; $i < 7; $i++) {
        $last = test()->withHeaders(bearer($user))->postJson("/api/v1/cars/{$car->id}/renewals", renewalBody());
    }
    $last->assertStatus(429);
});

it('requires a verified signed in owner', function () {
    $car = linkedCar(verifiedOwner());
    test()->postJson("/api/v1/cars/{$car->id}/renewals", renewalBody())->assertUnauthorized();
    $unverified = owner(['email_verified_at' => null]);
    startRenewal($unverified, linkedCar($unverified))->assertForbidden();
});

it('refuses the fake gateway in production', function () {
    config(['wasla.payment_gateway' => 'fake']);
    $this->app['env'] = 'production';
    expect(fn () => app(GatewayManager::class)->driver())->toThrow(RuntimeException::class);
});

it('has a fake gateway that honours the contract', function () {
    $gateway = app(PaymentGateway::class);
    expect($gateway)->toBeInstanceOf(FakeGateway::class)->and($gateway->name())->toBe('fake');

    $b = new BillingDetails('A B', 'addr', 'city', TCKN);
    $mk = fn () => $gateway->startCheckout(new CheckoutRequest(
        'WREF'.bin2hex(random_bytes(6)), 79900, 'TRY', 'a@b.co', '1.2.3.4', 'tr', 'Renewal', $b));
    [$s1, $s2] = [$mk(), $mk()];
    expect($s1->reference)->not->toBe($s2->reference)->and($s1->redirectUrl)->toContain($s1->reference);

    $good = Request::create('/cb', 'POST', ['reference' => $s1->reference, 'signature' => FakeGateway::sign($s1->reference)]);
    expect($gateway->verifyCallback($good)->reference)->toBe($s1->reference);
    $bad = Request::create('/cb', 'POST', ['reference' => $s1->reference, 'signature' => 'nope']);
    expect(fn () => $gateway->verifyCallback($bad))->toThrow(InvalidCallback::class);

    FakeGateway::setOutcome($s1->reference, PaymentStatus::Paid, 79900);
    $st = $gateway->fetchStatus($s1->reference);
    expect($st->status)->toBe(PaymentStatus::Paid)->and($st->paidAmountKurus)->toBe(79900)->and($st->currency)->toBe('TRY')
        ->and($gateway->callbackResponse()->getStatusCode())->toBe(200);
});

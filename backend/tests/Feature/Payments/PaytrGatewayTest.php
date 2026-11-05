<?php

use App\Enums\PaymentStatus;
use App\Models\Tag;
use App\Services\Payments\BillingDetails;
use App\Services\Payments\Drivers\PaytrGateway;
use App\Services\Payments\InvalidCallback;
use App\Services\Payments\PaymentGatewayNotConfigured;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Docs: base64 of the raw HMAC-SHA256 of merchant_oid.salt.status.total_amount, keyed with the merchant key. */
function paytrCallbackFields(array $overrides = []): array
{
    $f = array_merge([
        'merchant_oid' => GATEWAY_TEST_REFERENCE, 'status' => 'success', 'total_amount' => '79900',
        'payment_amount' => '79900', 'payment_type' => 'card', 'currency' => 'TL', 'test_mode' => '0',
    ], $overrides);
    $f['hash'] ??= base64_encode(hash_hmac('sha256', $f['merchant_oid'].PAYTR_TEST_SALT.$f['status'].$f['total_amount'], PAYTR_TEST_KEY, true));

    return $f;
}

function paytrStatusBody(array $overrides = []): string
{
    return json_encode(array_merge(json_decode(paymentFixture('paytr/status-success'), true), $overrides));
}

function paytrFakeStatus(string $body, int $status = 200): void
{
    Http::fake(['https://www.paytr.com/odeme/durum-sorgu' => Http::response($body, $status)]);
}

function paytrCallbackRequest(array $overrides = []): Request
{
    return Request::create('/cb', 'POST', paytrCallbackFields($overrides));
}

beforeEach(function () {
    config([
        'services.paytr.merchant_id' => PAYTR_TEST_ID,
        'services.paytr.merchant_key' => PAYTR_TEST_KEY,
        'services.paytr.merchant_salt' => PAYTR_TEST_SALT,
        'services.paytr.test_mode' => false,
        'app.url' => 'https://wasla.test',
    ]);
    $this->paytr = new PaytrGateway;
});

it('sends the required buyer fields and the kurus amount in the provider format', function () {
    Http::fake(['*' => Http::response(paymentFixture('paytr/token-success'))]);

    $this->paytr->startCheckout(checkoutRequest());

    Http::assertSent(function (ClientRequest $r) {
        parse_str($r->body(), $b);

        return $r->url() === 'https://www.paytr.com/odeme/api/get-token'
            && $r->isForm()
            && $b['merchant_id'] === PAYTR_TEST_ID
            && $b['user_ip'] === '203.0.113.9'
            && $b['merchant_oid'] === GATEWAY_TEST_REFERENCE
            && $b['email'] === 'ayse@example.test'
            && $b['payment_amount'] === '79905'
            && $b['currency'] === 'TL'
            && json_decode(base64_decode($b['user_basket']), true) === [['WASLA TAG0001', '799.05', 1]]
            && $b['no_installment'] === '1' && $b['max_installment'] === '0'
            && $b['user_name'] === 'Ayşe Nur Yılmaz'
            && $b['user_address'] === 'Moda Cad. No 5, İstanbul'
            && $b['user_phone'] === '+905550000001'
            && $b['merchant_ok_url'] === 'https://wasla.test/payments/return'
            && $b['merchant_fail_url'] === 'https://wasla.test/payments/return'
            && $b['test_mode'] === '0' && $b['debug_on'] === '0' && $b['lang'] === 'tr'
            && ! array_key_exists('merchant_key', $b) && ! array_key_exists('merchant_salt', $b)
            && ! str_contains($r->body(), GATEWAY_TEST_TCKN);
    });
});

it('keeps to the documented field limits', function () {
    Http::fake(['*' => Http::response(paymentFixture('paytr/token-success'))]);
    $billing = new BillingDetails(str_repeat('Ş', 70).' Yılmaz', 'A', 'B', GATEWAY_TEST_TCKN, '+905550000001');

    $this->paytr->startCheckout(checkoutRequest(['billing' => $billing]));

    Http::assertSent(function (ClientRequest $r) {
        parse_str($r->body(), $b);

        return mb_strlen($b['user_name']) === 60;
    });
});

it('refuses an email PayTR cannot take rather than altering it', function (string $email) {
    Http::fake();

    try {
        $this->paytr->startCheckout(checkoutRequest(['buyerEmail' => $email]));
        $this->fail('Expected an exception');
    } catch (RuntimeException) {
        Http::assertNothingSent();
    }
})->with(['turkish characters' => 'ayşe@example.test', 'too long' => str_repeat('a', 95).'@e.co.xx']);

it('returns the provider checkout URL and reference', function () {
    Http::fake(['*' => Http::response(paymentFixture('paytr/token-success'))]);

    $session = $this->paytr->startCheckout(checkoutRequest());

    expect($session->reference)->toBe(GATEWAY_TEST_REFERENCE)
        ->and($session->redirectUrl)->toBe('https://www.paytr.com/odeme/guvenli/28cc613c3d7633cfa4ed0956fdf901e05cf9d9cc0c2ef8db54fa');
});

it('signs the request as documented', function () {
    Http::fake(['*' => Http::response(paymentFixture('paytr/token-success'))]);

    $this->paytr->startCheckout(checkoutRequest());

    Http::assertSent(function (ClientRequest $r) {
        parse_str($r->body(), $b);
        // merchant_id, user_ip, merchant_oid, email, payment_amount, user_basket, no_installment, max_installment, currency, test_mode, then salt.
        $plain = PAYTR_TEST_ID.'203.0.113.9'.GATEWAY_TEST_REFERENCE.'ayse@example.test79905'.$b['user_basket'].'10TL0'.PAYTR_TEST_SALT;

        return $b['paytr_token'] === base64_encode(hash_hmac('sha256', $plain, PAYTR_TEST_KEY, true));
    });
});

it('signs the status query as documented', function () {
    paytrFakeStatus(paymentFixture('paytr/status-success'));

    $this->paytr->fetchStatus(GATEWAY_TEST_REFERENCE);

    Http::assertSent(function (ClientRequest $r) {
        parse_str($r->body(), $b);

        return $b['merchant_id'] === PAYTR_TEST_ID && $b['merchant_oid'] === GATEWAY_TEST_REFERENCE
            && $b['paytr_token'] === base64_encode(hash_hmac('sha256', PAYTR_TEST_ID.GATEWAY_TEST_REFERENCE.PAYTR_TEST_SALT, PAYTR_TEST_KEY, true));
    });
});

it('refuses a provider error on the token request without repeating its message', function () {
    Http::fake(['*' => Http::response(paymentFixture('paytr/token-error'))]);

    try {
        $this->paytr->startCheckout(checkoutRequest());
        $this->fail('Expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('Ayse');
    }
});

it('takes a provider timeout on the existing payment_unavailable path', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    renewThroughApi('paytr')->assertStatus(502)->assertJsonPath('code', 'payment_unavailable');
});

it('sets timeouts on provider calls', function () {
    $options = (new ReflectionMethod(PaytrGateway::class, 'client'))->invoke($this->paytr)->getOptions();

    expect($options['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(30)
        ->and($options['connect_timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
});

it('accepts a correctly signed callback', function () {
    expect($this->paytr->verifyCallback(paytrCallbackRequest())->reference)->toBe(GATEWAY_TEST_REFERENCE)
        ->and($this->paytr->verifyCallback(paytrCallbackRequest(['status' => 'failed']))->reference)->toBe(GATEWAY_TEST_REFERENCE);
});

it('rejects a tampered callback', function (array $overrides) {
    $this->paytr->verifyCallback(Request::create('/cb', 'POST', array_merge(paytrCallbackFields(), $overrides)));
})->with([
    'status flipped' => [['status' => 'failed']],
    'amount changed' => [['total_amount' => '1']],
    'other order' => [['merchant_oid' => 'WOTHER']],
    'wrong hash' => [['hash' => base64_encode(str_repeat('x', 32))]],
    'array hash' => [['hash' => ['x']]],
    'array reference' => [['merchant_oid' => ['x']]],
    'array status' => [['status' => ['success']]],
    'array amount' => [['total_amount' => ['79900']]],
    'no hash' => [['hash' => '']],
])->throws(InvalidCallback::class);

it('rejects a correctly hashed callback with an unknown status or an unusable reference', function (array $overrides) {
    $this->paytr->verifyCallback(paytrCallbackRequest($overrides));
})->with([
    'status' => [['status' => 'maybe']],
    'reference' => [['merchant_oid' => 'W-1/../2']],
])->throws(InvalidCallback::class);

it('maps provider statuses to paid and failed', function (array $overrides, PaymentStatus $status, int $kurus) {
    paytrFakeStatus(paytrStatusBody($overrides));

    $remote = $this->paytr->fetchStatus(GATEWAY_TEST_REFERENCE);

    expect($remote->status)->toBe($status)->and($remote->paidAmountKurus)->toBe($kurus)->and($remote->currency)->toBe('TRY');
})->with([
    'success' => [[], PaymentStatus::Paid, 79900],
    'comma decimal' => [['payment_total' => '799,05'], PaymentStatus::Paid, 79905],
    'whole lira' => [['payment_total' => '799'], PaymentStatus::Paid, 79900],
    'refunded' => [['returns' => [['return_amount' => '799.00']]], PaymentStatus::Refunded, 0],
    'test transaction on a live setup' => [['test_mode' => '1'], PaymentStatus::Pending, 0],
]);

it('reads a JSON number amount exactly', function () {
    paytrFakeStatus(str_replace('"payment_total": "799.00"', '"payment_total": 799.05', paymentFixture('paytr/status-success')));

    expect($this->paytr->fetchStatus(GATEWAY_TEST_REFERENCE)->paidAmountKurus)->toBe(79905);
});

it('reports an amount it cannot read instead of guessing', function (string $amount) {
    paytrFakeStatus(paytrStatusBody(['payment_total' => $amount]));

    $this->paytr->fetchStatus(GATEWAY_TEST_REFERENCE);
})->with(['thousands' => '1.234,56', 'three decimals' => '799.005', 'text' => 'free', 'negative' => '-799'])
    ->throws(RuntimeException::class);

it('marks failed from the signed callback when the status query finds no successful payment', function () {
    paytrFakeStatus(paymentFixture('paytr/status-not-found'));
    $this->paytr->verifyCallback(paytrCallbackRequest(['status' => 'failed', 'total_amount' => '0']));

    expect($this->paytr->fetchStatus(GATEWAY_TEST_REFERENCE)->status)->toBe(PaymentStatus::Failed);
});

it('asks for a retry when the callback says success but the query finds nothing yet', function () {
    paytrFakeStatus(paymentFixture('paytr/status-not-found'));
    $this->paytr->verifyCallback(paytrCallbackRequest());

    $this->paytr->fetchStatus(GATEWAY_TEST_REFERENCE);
})->throws(RuntimeException::class);

it('stays pending when the query finds nothing and no callback says otherwise', function () {
    paytrFakeStatus(paymentFixture('paytr/status-not-found'));

    expect($this->paytr->fetchStatus(GATEWAY_TEST_REFERENCE)->status)->toBe(PaymentStatus::Pending);
});

it('returns the exact callback response the provider expects', function () {
    $response = $this->paytr->callbackResponse();

    expect($response->getContent())->toBe('OK')->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('text/plain');
});

it('extends the subscription through the real callback route, and answers a literal OK', function () {
    $payment = gatewayPayment('paytr', GATEWAY_TEST_REFERENCE);
    $before = Tag::find($payment->tag_id)->expires_at;
    paytrFakeStatus(paymentFixture('paytr/status-success'));

    $response = $this->post('/payments/callback/paytr', paytrCallbackFields())->assertOk();
    $this->post('/payments/callback/paytr', paytrCallbackFields())->assertOk();

    expect($response->getContent())->toBe('OK')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(Tag::find($payment->tag_id)->expires_at->toDateTimeString())->toBe($before->copy()->addYear()->toDateTimeString());
});

it('does not extend when the signed callback says success but the provider says otherwise', function () {
    $payment = gatewayPayment('paytr', GATEWAY_TEST_REFERENCE);
    $before = Tag::find($payment->tag_id)->expires_at->toDateTimeString();
    paytrFakeStatus(paymentFixture('paytr/status-not-found'));

    $this->post('/payments/callback/paytr', paytrCallbackFields())->assertStatus(503);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(Tag::find($payment->tag_id)->expires_at->toDateTimeString())->toBe($before);
});

it('does not extend on a tampered callback through the route, and never calls the provider', function () {
    $payment = gatewayPayment('paytr', GATEWAY_TEST_REFERENCE);
    Http::fake();

    $this->post('/payments/callback/paytr', array_merge(paytrCallbackFields(), ['total_amount' => '1']))->assertStatus(400);
    $this->post('/payments/callback/paytr', array_merge(paytrCallbackFields(), ['merchant_oid' => ['x']]))->assertStatus(400);

    Http::assertNothingSent();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('flags a paid amount that differs from the recorded one', function () {
    $payment = gatewayPayment('paytr', GATEWAY_TEST_REFERENCE);
    paytrFakeStatus(paytrStatusBody(['payment_total' => '1.00']));

    $this->post('/payments/callback/paytr', paytrCallbackFields())->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)->and($payment->fresh()->flag_reason)->toBe('amount_mismatch');
});

it('answers 503 so PayTR retries when the status query is down', function () {
    $payment = gatewayPayment('paytr', GATEWAY_TEST_REFERENCE);
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $this->post('/payments/callback/paytr', paytrCallbackFields())->assertStatus(503);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('does not accept a callback signed with empty keys when the keys are missing', function () {
    config(['services.paytr.merchant_key' => '', 'services.paytr.merchant_salt' => '']);
    $payment = gatewayPayment('paytr', GATEWAY_TEST_REFERENCE);
    Http::fake();
    $forged = paytrCallbackFields(['hash' => base64_encode(hash_hmac('sha256', GATEWAY_TEST_REFERENCE.'success79900', '', true))]);

    expect(fn () => (new PaytrGateway)->verifyCallback(Request::create('/cb', 'POST', $forged)))->toThrow(PaymentGatewayNotConfigured::class);
    $this->post('/payments/callback/paytr', $forged)->assertStatus(503);

    Http::assertNothingSent();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('logs neither provider text nor billing data', function () {
    Log::spy();
    $payment = gatewayPayment('paytr', GATEWAY_TEST_REFERENCE);
    paytrFakeStatus(json_encode(['status' => 'error', 'err_no' => '999', 'err_msg' => 'Made up text '.GATEWAY_TEST_TCKN]));

    $this->post('/payments/callback/paytr', paytrCallbackFields())->assertStatus(503);

    Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => ! str_contains(json_encode([$m, $c]), 'Made up')
        && ! str_contains(json_encode([$m, $c]), GATEWAY_TEST_TCKN));
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

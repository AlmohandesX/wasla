<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Tag;
use App\Services\Payments\BillingDetails;
use App\Services\Payments\Drivers\IyzicoGateway;
use App\Services\Payments\InvalidCallback;
use App\Services\Payments\PaymentGatewayNotConfigured;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

const IYZICO_INIT_PATH = '/payment/iyzipos/checkoutform/initialize/auth/ecom';
const IYZICO_RETRIEVE_PATH = '/payment/iyzipos/checkoutform/auth/ecom/detail';

/** Docs: HMAC-SHA256 with the secret key, hex, values joined with ":". */
function iyzicoSign(array $values): string
{
    return hash_hmac('sha256', implode(':', $values), IYZICO_TEST_SECRET);
}

/** Signs conversationId and token unless the override supplies a signature. */
function iyzicoInitBody(array $overrides = []): string
{
    $data = array_merge(json_decode(paymentFixture('iyzico/initialize-success'), true), $overrides);
    if (! isset($overrides['signature'])) {
        $data['signature'] = iyzicoSign([$data['conversationId'], $data['token']]);
    }

    return json_encode($data);
}

/** Works on the raw fixture text so amounts stay JSON numbers with eight decimals, as the docs' sample shows. */
function iyzicoRetrieveBody(array $overrides = [], ?string $signature = null): string
{
    $v = array_merge([
        'paymentStatus' => 'SUCCESS', 'paymentId' => '19831123', 'currency' => 'TRY', 'fraudStatus' => 1,
        'paidPrice' => '799.00000000', 'price' => '799.00000000', 'token' => IYZICO_TEST_TOKEN,
    ], $overrides);

    $body = paymentFixture('iyzico/retrieve-success');
    $body = preg_replace('/^  "paidPrice": [\d.]+/m', '  "paidPrice": '.$v['paidPrice'], $body);
    $body = preg_replace('/^  "price": [\d.]+/m', '  "price": '.$v['price'], $body);
    $body = str_replace('"fraudStatus": 1', '"fraudStatus": '.$v['fraudStatus'], $body);
    $body = str_replace('"paymentStatus": "SUCCESS"', '"paymentStatus": "'.$v['paymentStatus'].'"', $body);
    $body = str_replace('"token": "'.IYZICO_TEST_TOKEN.'"', '"token": "'.$v['token'].'"', $body);

    $trim = fn (string $p) => str_contains($p, '.') ? rtrim(rtrim($p, '0'), '.') : $p;
    $signature ??= iyzicoSign([
        $v['paymentStatus'], $v['paymentId'], $v['currency'], GATEWAY_TEST_REFERENCE, GATEWAY_TEST_REFERENCE,
        $trim($v['paidPrice']), $trim($v['price']), $v['token'],
    ]);

    return str_replace('computed-by-the-test', $signature, $body);
}

function iyzicoFakeRetrieve(string $body, int $status = 200): void
{
    Http::fake(['*'.IYZICO_RETRIEVE_PATH => Http::response($body, $status)]);
}

function iyzicoCallback(array $body = ['token' => IYZICO_TEST_TOKEN])
{
    return test()->post('/payments/callback/iyzico', $body);
}

beforeEach(function () {
    config([
        'services.iyzico.api_key' => IYZICO_TEST_KEY,
        'services.iyzico.secret_key' => IYZICO_TEST_SECRET,
        'services.iyzico.base_url' => 'https://sandbox-api.iyzipay.com',
        'app.url' => 'https://wasla.test',
    ]);
    $this->iyzico = new IyzicoGateway;
});

it('sends the required buyer fields and the kurus amount in the provider format', function () {
    Http::fake(['*' => Http::response(iyzicoInitBody())]);

    $this->iyzico->startCheckout(checkoutRequest());

    Http::assertSent(function (ClientRequest $r) {
        $b = json_decode($r->body(), true);

        return $r->url() === 'https://sandbox-api.iyzipay.com'.IYZICO_INIT_PATH
            && $r->method() === 'POST'
            && $b['price'] === '799.05' && $b['paidPrice'] === '799.05'
            && $b['currency'] === 'TRY' && $b['locale'] === 'tr'
            && $b['conversationId'] === GATEWAY_TEST_REFERENCE && $b['basketId'] === GATEWAY_TEST_REFERENCE
            && $b['paymentGroup'] === 'PRODUCT'
            && $b['enabledInstallments'] === [1]
            && $b['callbackUrl'] === 'https://wasla.test/payments/callback/iyzico'
            && $b['buyer'] === [
                'id' => '42', 'name' => 'Ayşe Nur', 'surname' => 'Yılmaz', 'identityNumber' => GATEWAY_TEST_TCKN,
                'email' => 'ayse@example.test', 'gsmNumber' => '+905550000001',
                'registrationAddress' => 'Moda Cad. No 5', 'city' => 'İstanbul', 'country' => 'Turkey', 'ip' => '203.0.113.9',
            ]
            && $b['billingAddress'] === ['contactName' => 'Ayşe Nur Yılmaz', 'city' => 'İstanbul', 'country' => 'Turkey', 'address' => 'Moda Cad. No 5']
            && $b['shippingAddress'] === $b['billingAddress']
            && $b['basketItems'] === [[
                'id' => GATEWAY_TEST_REFERENCE, 'name' => 'WASLA TAG0001', 'category1' => 'Subscription',
                'itemType' => 'VIRTUAL', 'price' => '799.05',
            ]];
    });
});

it('builds the callback URL from APP_URL, not the request host', function () {
    Http::fake(['*' => Http::response(iyzicoInitBody())]);
    URL::forceRootUrl('https://evil.test');

    $this->iyzico->startCheckout(checkoutRequest());
    URL::forceRootUrl(null);

    Http::assertSent(fn (ClientRequest $r) => json_decode($r->body(), true)['callbackUrl'] === 'https://wasla.test/payments/callback/iyzico');
});

it('returns the provider checkout URL and reference', function () {
    Http::fake(['*' => Http::response(iyzicoInitBody())]);

    $session = $this->iyzico->startCheckout(checkoutRequest());

    expect($session->reference)->toBe(IYZICO_TEST_TOKEN)
        ->and($session->redirectUrl)->toBe('https://sandbox-cpp.iyzipay.com?token='.IYZICO_TEST_TOKEN.'&lang=tr');
});

it('signs the request as documented', function () {
    Http::fake(['*' => Http::response(iyzicoInitBody())]);

    $this->iyzico->startCheckout(checkoutRequest());

    Http::assertSent(function (ClientRequest $r) {
        $rnd = $r->header('x-iyzi-rnd')[0];
        $signature = hash_hmac('sha256', $rnd.IYZICO_INIT_PATH.$r->body(), IYZICO_TEST_SECRET);
        $expected = 'IYZWSv2 '.base64_encode('apiKey:'.IYZICO_TEST_KEY.'&randomKey:'.$rnd.'&signature:'.$signature);

        return $rnd !== '' && $r->header('Authorization')[0] === $expected;
    });
});

it('uses a fresh random key for each request', function () {
    Http::fake(['*' => Http::response(iyzicoInitBody())]);
    $seen = [];

    $this->iyzico->startCheckout(checkoutRequest());
    $this->iyzico->startCheckout(checkoutRequest());

    Http::assertSent(function (ClientRequest $r) use (&$seen) {
        $seen[] = $r->header('x-iyzi-rnd')[0];

        return true;
    });
    expect(array_unique($seen))->toHaveCount(2);
});

it('refuses an initialize response that fails its checks', function (Closure $overrides) {
    Http::fake(['*' => Http::response(iyzicoInitBody($overrides()))]);

    $this->iyzico->startCheckout(checkoutRequest());
})->with([
    'bad signature' => [fn () => ['signature' => str_repeat('a', 64)]],
    'another conversation' => [fn () => ['conversationId' => 'WOTHER']],
    'plain http page' => [fn () => ['paymentPageUrl' => 'http://sandbox-cpp.iyzipay.com?token=x']],
    'oversized token' => [fn () => ['token' => str_repeat('a', 101)]],
])->throws(RuntimeException::class);

it('refuses a provider error on initialize without repeating its message', function () {
    Http::fake(['*' => Http::response(paymentFixture('iyzico/initialize-error'), 400)]);

    try {
        $this->iyzico->startCheckout(checkoutRequest());
        $this->fail('Expected an exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain(GATEWAY_TEST_TCKN);
    }
});

it('takes a provider timeout on the existing payment_unavailable path', function () {
    Http::fake(fn () => throw new ConnectionException('timed out for '.GATEWAY_TEST_TCKN));
    Log::spy();

    $res = renewThroughApi('iyzico');

    $res->assertStatus(502)->assertJsonPath('code', 'payment_unavailable');
    expect($res->getContent())->not->toContain(GATEWAY_TEST_TCKN);
    Log::shouldHaveReceived('warning');
    assertNotLogged(GATEWAY_TEST_TCKN);
});

it('stores the provider token as the payment reference', function () {
    Http::fake(['*' => function (ClientRequest $r) {
        $conversationId = json_decode($r->body(), true)['conversationId'];

        return Http::response(iyzicoInitBody(['conversationId' => $conversationId]));
    }]);

    $res = renewThroughApi('iyzico')->assertCreated();

    expect($res->json('data.redirect_url'))->toContain('sandbox-cpp.iyzipay.com')
        ->and(Payment::first()->reference)->toBe(IYZICO_TEST_TOKEN)
        ->and(Payment::first()->provider)->toBe('iyzico');
});

it('sets timeouts on provider calls', function () {
    $options = (new ReflectionMethod(IyzicoGateway::class, 'client'))->invoke($this->iyzico)->getOptions();

    expect($options['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(30)
        ->and($options['connect_timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
});

it('accepts a correctly signed callback, by a signed retrieve from the provider', function () {
    iyzicoFakeRetrieve(iyzicoRetrieveBody());

    $callback = $this->iyzico->verifyCallback(Request::create('/cb', 'POST', ['token' => IYZICO_TEST_TOKEN]));

    expect($callback->reference)->toBe(IYZICO_TEST_TOKEN);
    Http::assertSent(function (ClientRequest $r) {
        $rnd = $r->header('x-iyzi-rnd')[0];
        $signature = hash_hmac('sha256', $rnd.IYZICO_RETRIEVE_PATH.$r->body(), IYZICO_TEST_SECRET);

        return str_ends_with($r->url(), IYZICO_RETRIEVE_PATH)
            && json_decode($r->body(), true)['token'] === IYZICO_TEST_TOKEN
            && $r->header('Authorization')[0] === 'IYZWSv2 '.base64_encode('apiKey:'.IYZICO_TEST_KEY.'&randomKey:'.$rnd.'&signature:'.$signature);
    });
});

it('checks the response signature with trailing zeros removed, as the docs say', function () {
    // Literal: this is the documented order and normalization, not derived from any helper.
    $signed = 'SUCCESS:19831123:TRY:'.GATEWAY_TEST_REFERENCE.':'.GATEWAY_TEST_REFERENCE.':799.5:799.5:'.IYZICO_TEST_TOKEN;
    $body = iyzicoRetrieveBody(['paidPrice' => '799.50000000', 'price' => '799.50000000'], hash_hmac('sha256', $signed, IYZICO_TEST_SECRET));
    iyzicoFakeRetrieve($body);

    expect($this->iyzico->verifyCallback(Request::create('/cb', 'POST', ['token' => IYZICO_TEST_TOKEN]))->reference)
        ->toBe(IYZICO_TEST_TOKEN);
});

it('rejects a tampered callback', function (Closure $body) {
    iyzicoFakeRetrieve($body());

    $this->iyzico->verifyCallback(Request::create('/cb', 'POST', ['token' => IYZICO_TEST_TOKEN]));
})->with([
    'wrong signature' => [fn () => iyzicoRetrieveBody([], str_repeat('0', 64))],
    'status changed after signing' => [fn () => str_replace('"paymentStatus": "FAILURE"', '"paymentStatus": "SUCCESS"', iyzicoRetrieveBody(['paymentStatus' => 'FAILURE']))],
    'amount changed after signing' => [fn () => preg_replace('/^  "paidPrice": [\d.]+/m', '  "paidPrice": 1.00000000', iyzicoRetrieveBody())],
    'no signature' => [fn () => preg_replace('/,\s*"signature": "[^"]*"/', '', iyzicoRetrieveBody())],
    'array signature' => [fn () => preg_replace('/"signature": "[^"]*"/', '"signature": ["x"]', iyzicoRetrieveBody())],
    'the token of another payment' => [fn () => iyzicoRetrieveBody(['token' => 'cccccccc-1111-4222-8333-bbbbbbbbbbbb'])],
    'provider says no such token' => [fn () => paymentFixture('iyzico/retrieve-error')],
])->throws(InvalidCallback::class);

it('rejects callback input that is not a usable token, without calling the provider', function (array $input) {
    Http::fake();

    try {
        $this->iyzico->verifyCallback(Request::create('/cb', 'POST', $input));
        $this->fail('Expected InvalidCallback');
    } catch (InvalidCallback) {
        Http::assertNothingSent();
    }
})->with([
    'missing' => [[]],
    'empty' => [[['token' => '']]],
    'array' => [[['token' => ['x']]]],
    'odd characters' => [[['token' => "abc\n../x"]]],
    'too long' => [[['token' => str_repeat('a', 101)]]],
]);

it('maps provider statuses to paid and failed', function (array $overrides, PaymentStatus $status, int $kurus) {
    iyzicoFakeRetrieve(iyzicoRetrieveBody($overrides));

    $remote = $this->iyzico->fetchStatus(IYZICO_TEST_TOKEN);

    expect($remote->status)->toBe($status)->and($remote->paidAmountKurus)->toBe($kurus)->and($remote->currency)->toBe('TRY');
})->with([
    'success' => [[], PaymentStatus::Paid, 79900],
    'with kurus' => [['paidPrice' => '799.05000000', 'price' => '799.05000000'], PaymentStatus::Paid, 79905],
    'failure' => [['paymentStatus' => 'FAILURE'], PaymentStatus::Failed, 0],
    'fraud rejected' => [['fraudStatus' => -1], PaymentStatus::Failed, 0],
    'fraud in review' => [['fraudStatus' => 0], PaymentStatus::Pending, 0],
    'unfinished 3DS' => [['paymentStatus' => 'INIT_THREEDS'], PaymentStatus::Pending, 0],
]);

it('reports an amount that is not whole kurus instead of rounding it', function () {
    iyzicoFakeRetrieve(iyzicoRetrieveBody(['paidPrice' => '799.00500000', 'price' => '799.00500000']));

    $this->iyzico->fetchStatus(IYZICO_TEST_TOKEN);
})->throws(RuntimeException::class);

it('returns the exact callback response the provider expects', function () {
    // The owner's browser lands on this POST and an optional webhook needs a 2xx, so: 200 and on to the return page.
    $response = $this->iyzico->callbackResponse();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->toContain('http-equiv="refresh"')->toContain('https://wasla.test/payments/return');
});

it('extends the subscription through the real callback route', function () {
    $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
    $before = Tag::find($payment->tag_id)->expires_at;
    iyzicoFakeRetrieve(iyzicoRetrieveBody());

    iyzicoCallback()->assertOk()->assertSee('http-equiv="refresh"', false);
    iyzicoCallback()->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(Tag::find($payment->tag_id)->expires_at->toDateTimeString())->toBe($before->copy()->addYear()->toDateTimeString());
});

it('accepts the same token from a JSON webhook, still trusting only the signed retrieve', function () {
    $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
    iyzicoFakeRetrieve(iyzicoRetrieveBody());

    $this->postJson('/payments/callback/iyzico', [
        'iyziEventType' => 'CHECKOUT_FORM_AUTH', 'iyziPaymentId' => 19831123, 'token' => IYZICO_TEST_TOKEN,
        'paymentConversationId' => GATEWAY_TEST_REFERENCE, 'status' => 'SUCCESS',
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('does not extend on a tampered callback through the route', function () {
    $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
    $before = Tag::find($payment->tag_id)->expires_at->toDateTimeString();
    iyzicoFakeRetrieve(iyzicoRetrieveBody([], str_repeat('0', 64)));

    iyzicoCallback()->assertStatus(400);
    iyzicoCallback(['token' => ['x']])->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(Tag::find($payment->tag_id)->expires_at->toDateTimeString())->toBe($before);
});

it('flags a paid amount that differs from the recorded one', function () {
    $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
    iyzicoFakeRetrieve(iyzicoRetrieveBody(['paidPrice' => '1.00000000', 'price' => '1.00000000']));

    iyzicoCallback()->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)->and($payment->fresh()->flag_reason)->toBe('amount_mismatch');
});

it('answers 503 so a retry is possible when the provider times out', function () {
    $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
    Http::fake(fn () => throw new ConnectionException('timed out'));

    iyzicoCallback()->assertStatus(503);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('answers 503 so a retry is possible when the provider is down', function () {
    $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
    iyzicoFakeRetrieve('Bad gateway', 502);

    iyzicoCallback()->assertStatus(503);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('never signs with missing keys, and says so instead of a silent 404', function () {
    config(['services.iyzico.secret_key' => '']);
    Http::fake();
    $gateway = new IyzicoGateway;

    expect(fn () => $gateway->startCheckout(checkoutRequest()))->toThrow(PaymentGatewayNotConfigured::class)
        ->and(fn () => $gateway->verifyCallback(Request::create('/cb', 'POST', ['token' => IYZICO_TEST_TOKEN])))->toThrow(PaymentGatewayNotConfigured::class);

    iyzicoCallback()->assertStatus(503);
    Http::assertNothingSent();
});

it('logs neither provider text nor billing data when a callback is refused', function () {
    Log::spy();
    iyzicoFakeRetrieve(str_replace('Made up provider text', 'Made up provider text '.GATEWAY_TEST_TCKN, paymentFixture('iyzico/retrieve-error')), 400);

    iyzicoCallback()->assertStatus(400);

    Log::shouldHaveReceived('warning');
    assertNotLogged(GATEWAY_TEST_TCKN, 'Made up');
});

describe('what the owner sees and what a webhook sees', function () {
    it('shows the translated return page to a browser, keeping the status', function (int $providerStatus, int $expected) {
        gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
        iyzicoFakeRetrieve($providerStatus === 200 ? iyzicoRetrieveBody([], str_repeat('0', 64)) : 'Bad gateway', $providerStatus);

        iyzicoCallback()->assertStatus($expected)
            ->assertSee(__('wasla.payment_return_title'))
            ->assertDontSee('Invalid callback.')->assertDontSee('Try again later.');
    })->with(['refused' => [200, 400], 'provider down' => [502, 503]]);

    it('keeps a plain answer for a JSON webhook', function (int $providerStatus, int $expected, string $text) {
        gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
        iyzicoFakeRetrieve($providerStatus === 200 ? iyzicoRetrieveBody([], str_repeat('0', 64)) : 'Bad gateway', $providerStatus);

        expect($this->postJson('/payments/callback/iyzico', ['token' => IYZICO_TEST_TOKEN])->assertStatus($expected)->getContent())
            ->toBe($text);
    })->with(['refused' => [200, 400, 'Invalid callback.'], 'provider down' => [502, 503, 'Try again later.']]);
});

describe('an ordinary decline', function () {
    it('marks the payment failed from a signed FAILURE, whatever the top level status says', function () {
        $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
        $body = str_replace('"status": "success"', '"status": "failure"', iyzicoRetrieveBody(['paymentStatus' => 'FAILURE']));
        iyzicoFakeRetrieve($body);

        iyzicoCallback()->assertOk();

        expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('refuses a FAILURE that is not correctly signed', function () {
        $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
        $body = str_replace('"status": "success"', '"status": "failure"', iyzicoRetrieveBody(['paymentStatus' => 'FAILURE'], str_repeat('0', 64)));
        iyzicoFakeRetrieve($body);

        iyzicoCallback()->assertStatus(400);

        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    });
});

it('treats an iyzico auth or system error as our outage, not a bad token', function (mixed $code) {
    Log::spy();
    $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
    iyzicoFakeRetrieve(json_encode(['status' => 'failure', 'errorCode' => $code, 'errorMessage' => 'Made up text']), 401);

    iyzicoCallback()->assertStatus(503);

    Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => ($c['error_code'] ?? null) === (string) $code);
    assertNotLogged('Made up');
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
})->with(['system' => '1', 'system 2' => '2', 'bad signature' => '1000', 'merchant' => '1002', 'header' => '1009', 'as a number' => 1001]);

it('logs the payment id when the status check fails on a verified callback', function () {
    Log::spy();
    $payment = gatewayPayment('iyzico', IYZICO_TEST_TOKEN);
    Http::fakeSequence('*')->push(iyzicoRetrieveBody())->push('Bad gateway', 502);

    iyzicoCallback()->assertStatus(503);

    Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => ($c['payment_id'] ?? null) === $payment->id);
});

it('never sends an empty name part when the name has odd spaces', function () {
    Http::fake(['*' => Http::response(iyzicoInitBody())]);
    $nbsp = "\u{00A0}";

    $this->iyzico->startCheckout(checkoutRequest([
        'billing' => new BillingDetails($nbsp.'Ayşe'.$nbsp.'Yılmaz'.$nbsp, 'A', 'B', GATEWAY_TEST_TCKN, '+905550000001'),
    ]));

    Http::assertSent(function (ClientRequest $r) {
        $buyer = json_decode($r->body(), true)['buyer'];

        return $buyer['name'] === 'Ayşe' && $buyer['surname'] === 'Yılmaz';
    });
});

it('refuses a name that is only one word once odd spaces are ignored', function () {
    Http::fake();

    try {
        $this->iyzico->startCheckout(checkoutRequest([
            'billing' => new BillingDetails("Ayşe\u{00A0}", 'A', 'B', GATEWAY_TEST_TCKN, '+905550000001'),
        ]));
        $this->fail('Expected an exception');
    } catch (RuntimeException) {
        Http::assertNothingSent();
    }
});

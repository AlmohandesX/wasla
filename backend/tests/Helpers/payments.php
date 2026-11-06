<?php

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Payments\BillingDetails;
use App\Services\Payments\CheckoutRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

const IYZICO_TEST_KEY = 'sandbox-made-up-api-key';
const IYZICO_TEST_SECRET = 'sandbox-made-up-secret';
const IYZICO_TEST_TOKEN = 'aaaaaaaa-1111-4222-8333-bbbbbbbbbbbb';
const PAYTR_TEST_ID = '100001';
const PAYTR_TEST_KEY = 'made-up-merchant-key';
const PAYTR_TEST_SALT = 'made-up-merchant-salt';
const GATEWAY_TEST_REFERENCE = 'WMADEUPREFERENCE0000000001';
const GATEWAY_TEST_TCKN = '10000000146';

function paymentFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../Fixtures/payments/'.$name.'.json');
}

function checkoutRequest(array $overrides = []): CheckoutRequest
{
    return new CheckoutRequest(...array_merge([
        'merchantReference' => GATEWAY_TEST_REFERENCE,
        'amountKurus' => 79905,
        'currency' => 'TRY',
        'buyerEmail' => 'ayse@example.test',
        'buyerIp' => '203.0.113.9',
        'locale' => 'tr',
        'description' => 'WASLA TAG0001',
        'billing' => new BillingDetails('Ayşe Nur Yılmaz', 'Moda Cad. No 5', 'İstanbul', GATEWAY_TEST_TCKN, '+905550000001'),
        'buyerId' => '42',
    ], $overrides));
}

/** A pending payment for a provider, on a tag that expires in a month. */
function gatewayPayment(string $provider, string $reference, int $amountKurus = 79900): Payment
{
    $user = owner(['email_verified_at' => now()]);

    return Payment::create([
        'tag_id' => linkedCar($user, now()->addMonth())->tag->id,
        'user_id' => $user->id,
        'provider' => $provider,
        'reference' => $reference,
        'amount_kurus' => $amountKurus,
        'currency' => 'TRY',
        'status' => PaymentStatus::Pending,
        'legal_accepted_at' => now(),
        'legal_text_version' => '1',
    ]);
}

/** Starts a renewal through the real API with the configured gateway. */
function renewThroughApi(string $gateway)
{
    config(['wasla.payment_gateway' => $gateway]);
    Setting::query()->updateOrCreate(['key' => 'renewal_price_kurus'], ['value' => '79900']);
    Cache::flush();
    $user = owner(['email_verified_at' => now()]);

    return test()->withHeaders(bearer($user))->postJson('/api/v1/cars/'.linkedCar($user)->id.'/renewals', [
        'billing_name' => 'Ayse Yilmaz', 'billing_address' => 'Moda Cad. No 5', 'billing_city' => 'Istanbul',
        'billing_national_id' => GATEWAY_TEST_TCKN, 'billing_phone' => '05550000001', 'legal_accepted' => true,
    ]);
}

/** Fails if any log call, at any level, contains one of the texts. Needs Log::spy(). */
function assertNotLogged(string ...$texts): void
{
    $leaks = function ($message = '', $context = []) use ($texts) {
        $line = json_encode([$message, $context]);

        return array_filter($texts, fn (string $text) => str_contains($line, $text)) !== [];
    };

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        Log::shouldNotHaveReceived($level, $leaks);
    }
}

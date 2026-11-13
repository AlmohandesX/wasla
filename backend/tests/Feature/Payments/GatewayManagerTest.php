<?php

use App\Services\Payments\BillingDetails;
use App\Services\Payments\Drivers\IyzicoGateway;
use App\Services\Payments\Drivers\PaytrGateway;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\Kurus;
use App\Services\Payments\UnknownPaymentGateway;
use App\Support\ProductionGuard;
use Illuminate\Support\Facades\Log;
use Mockery\Exception\InvalidCountException;

it('builds the real drivers by name', function () {
    expect(app(GatewayManager::class)->driver('iyzico'))->toBeInstanceOf(IyzicoGateway::class)
        ->and(app(GatewayManager::class)->driver('paytr'))->toBeInstanceOf(PaytrGateway::class);
});

it('has a dedicated exception for an unknown provider', function () {
    app(GatewayManager::class)->driver('nope');
})->throws(UnknownPaymentGateway::class);

it('keeps the driver config, ProductionGuard and .env.example on the same variable names', function () {
    $services = file_get_contents(config_path('services.php'));
    $example = file_get_contents(base_path('.env.example'));

    foreach (['IYZICO_API_KEY', 'IYZICO_SECRET_KEY', 'IYZICO_BASE_URL', 'PAYTR_MERCHANT_ID', 'PAYTR_MERCHANT_KEY', 'PAYTR_MERCHANT_SALT', 'PAYTR_TEST_MODE'] as $name) {
        expect(ProductionGuard::variableNames())->toContain($name)
            ->and($services)->toContain("env('$name'")
            ->and($example)->toContain("$name=");
    }
});

it('masks the phone number like the national ID', function () {
    $billing = new BillingDetails('A B', 'addr', 'city', '10000000146', '+905550000001');

    expect(print_r($billing, true).json_encode($billing))->not->toContain('5550000001')->not->toContain('10000000146');
});

it('converts kurus to the decimal text providers want', function (int $kurus, string $text) {
    expect(Kurus::toDecimal($kurus))->toBe($text);
})->with([[79900, '799.00'], [79905, '799.05'], [5, '0.05'], [1, '0.01'], [4294967295, '42949672.95']]);

it('reads provider decimals without floats', function (mixed $text, ?int $kurus) {
    expect(Kurus::fromDecimal($text))->toBe($kurus);
})->with([
    ['799.00000000', 79900], ['799', 79900], ['799,05', 79905], ['0.07', 7], ['0.29', 29], ['1.1', 110], ['19.99', 1999],
    ['799.005', null], ['1.234,56', null], ['-1', null], ['', null], ['abc', null], ['99999999999999999999', null],
    ["799\n", null], ["799.00\n", null], [799.0, null], [null, null], [[['1']], null],
]);

it('keeps JSON number amounts as text', function () {
    $data = Kurus::decodeJson('{"price":5.20000000,"paidPrice": 19.99,"note":"\"price\":1","other":1.5}', ['price', 'paidPrice']);

    expect($data['price'])->toBe('5.20000000')->and($data['paidPrice'])->toBe('19.99')
        ->and($data['note'])->toBe('"price":1')->and($data['other'])->toBe(1.5)
        ->and(Kurus::decodeJson('not json', ['price']))->toBeNull()
        ->and(Kurus::decodeJson('"text"', ['price']))->toBeNull();
});

it('has a log leak assertion that fails when only one of several calls leaks', function () {
    Log::spy();
    Log::warning('clean');
    Log::error('clean', ['context' => 'secret-value']);

    assertNotLogged('not-there');
    expect(fn () => assertNotLogged('secret-value'))->toThrow(InvalidCountException::class);
});

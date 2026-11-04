<?php

use App\Support\ProductionGuard;


it('fails when debug is on in production', function () {
    ProductionGuard::validate(productionEnv(['APP_DEBUG' => 'true']));
})->throws(RuntimeException::class, 'APP_DEBUG');

it('fails when a required variable is missing', function () {
    $env = productionEnv();
    unset($env['OPENAI_API_KEY']);
    ProductionGuard::validate($env);
})->throws(RuntimeException::class, 'OPENAI_API_KEY');

it('fails when the selected gateway keys are missing', function () {
    ProductionGuard::validate(productionEnv(['PAYMENT_GATEWAY' => 'paytr']));
})->throws(RuntimeException::class, 'PAYTR_MERCHANT_KEY');

it('fails when APP_URL or TAG_BASE_URL is not https', function () {
    try {
        ProductionGuard::validate(productionEnv(['APP_URL' => 'http://a.test', 'TAG_BASE_URL' => 'http://a.test/t']));
        $this->fail('Expected exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('APP_URL')->toContain('TAG_BASE_URL');
    }
});

it('fails when PAYMENT_GATEWAY is fake in production', function () {
    ProductionGuard::validate(productionEnv(['PAYMENT_GATEWAY' => 'fake']));
})->throws(RuntimeException::class, 'PAYMENT_GATEWAY');

it('fails on an unknown APP_ENV', function () {
    ProductionGuard::validate(['APP_ENV' => 'staging-x']);
})->throws(RuntimeException::class, 'APP_ENV');

it('names every problem at once', function () {
    try {
        ProductionGuard::validate(productionEnv(['APP_DEBUG' => 'true', 'PAYMENT_GATEWAY' => 'fake', 'APP_KEY' => '']));
        $this->fail('Expected exception');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('APP_DEBUG')->toContain('PAYMENT_GATEWAY')->toContain('APP_KEY');
    }
});

it('passes on a complete production environment', function () {
    ProductionGuard::validate(productionEnv());
    ProductionGuard::validate(productionEnv(['PAYMENT_GATEWAY' => 'paytr', 'PAYTR_MERCHANT_ID' => '1', 'PAYTR_MERCHANT_KEY' => 'k', 'PAYTR_MERCHANT_SALT' => 's']));
    expect(true)->toBeTrue();
});

it('does not demand production variables outside production', function () {
    ProductionGuard::validate(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);
    ProductionGuard::validate(['APP_ENV' => 'testing']);
    expect(true)->toBeTrue();
});

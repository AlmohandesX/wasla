<?php

use App\Support\CheckoutLifetime;
use App\Support\ProductionGuard;

it('clamps the checkout lifetime to 30..60 and never returns zero', function (mixed $raw, int $expected) {
    expect(CheckoutLifetime::fromEnv($raw))->toBe($expected);
})->with([
    'unset' => [null, 30],
    'empty' => ['', 30],
    'text' => ['abc', 30],
    'zero' => ['0', 30],
    'negative' => ['-5', 30],
    'below the iyzico token life' => ['10', 30],
    'in range' => ['45', 45],
    'upper bound' => ['60', 60],
    'above' => ['600', 60],
]);

it('refuses an out-of-range checkout lifetime in production', function (string $value) {
    ProductionGuard::validate(productionEnv(['CHECKOUT_LIFETIME_MINUTES' => $value]));
})->with(['', '0', '-1', '29', '61', 'abc', '30.5'])->throws(RuntimeException::class, 'CHECKOUT_LIFETIME_MINUTES');

it('accepts the allowed checkout lifetimes in production', function (string $value) {
    ProductionGuard::validate(productionEnv(['CHECKOUT_LIFETIME_MINUTES' => $value]));
    expect(true)->toBeTrue();
})->with(['30', '45', '60']);

it('reads the checkout lifetime into the guard environment', function () {
    expect(ProductionGuard::variableNames())->toContain('CHECKOUT_LIFETIME_MINUTES');
});

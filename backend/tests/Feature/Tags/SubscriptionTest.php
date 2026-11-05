<?php

use App\Services\Tags\SubscriptionService;

beforeEach(fn () => $this->travelTo(now()->startOfSecond()));

it('is expired at exactly expires_at', function () {
    $tag = tagFixture(['expires_at' => now()->addDay()]);
    $svc = new SubscriptionService;

    $this->travelTo($tag->expires_at->copy()->subSecond());
    expect($svc->isActive($tag))->toBeTrue();
    $this->travelTo($tag->expires_at);
    expect($svc->isActive($tag))->toBeFalse();
});

it('is inactive when never activated', function () {
    expect((new SubscriptionService)->isActive(tagFixture()))->toBeFalse();
});

it('extends from expiry when renewing early', function () {
    $old = now()->addDays(30);
    $tag = tagFixture(['expires_at' => $old]);

    $new = (new SubscriptionService)->extendOneYear($tag);

    expect($new->equalTo($old->copy()->addYear()))->toBeTrue()
        ->and($tag->refresh()->expires_at->equalTo($new))->toBeTrue();
});

it('extends from now when renewing after expiry', function () {
    $tag = tagFixture(['expires_at' => now()->subDays(60)]);
    $new = (new SubscriptionService)->extendOneYear($tag);
    expect($new->equalTo(now()->addYear()))->toBeTrue();
});

it('shortens by exactly one year', function () {
    $old = now()->addYear();
    $tag = tagFixture(['expires_at' => $old]);
    $new = (new SubscriptionService)->shortenOneYear($tag);
    expect($new->equalTo($old->copy()->subYear()))->toBeTrue()
        ->and($tag->refresh()->expires_at->equalTo($new))->toBeTrue();
});

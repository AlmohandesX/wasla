<?php

use App\Models\Tag;
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
    $tag = tagFixture(['expires_at' => $old, 'first_activated_at' => now()->subDay()]);

    $new = (new SubscriptionService)->extendOneYear($tag);

    expect($new->equalTo($old->copy()->addYear()))->toBeTrue()
        ->and($tag->refresh()->expires_at->equalTo($new))->toBeTrue();
});

it('extends from now when renewing after expiry', function () {
    $tag = tagFixture(['expires_at' => now()->subDays(60), 'first_activated_at' => now()->subYear()]);
    $new = (new SubscriptionService)->extendOneYear($tag);
    expect($new->equalTo(now()->addYear()))->toBeTrue();
});

it('shortens by exactly one year', function () {
    $old = now()->addYear();
    $tag = tagFixture(['expires_at' => $old, 'first_activated_at' => now()->subDay()]);
    $new = (new SubscriptionService)->shortenOneYear($tag);
    expect($new->equalTo($old->copy()->subYear()))->toBeTrue()
        ->and($tag->refresh()->expires_at->equalTo($new))->toBeTrue();
});

it('extends from the current database value even with a stale model', function () {
    $tag = tagFixture(['expires_at' => now()->addDays(30), 'first_activated_at' => now()->subDay()]);
    $stale = Tag::find($tag->id);
    $svc = new SubscriptionService;

    $svc->extendOneYear($tag);
    $new = $svc->extendOneYear($stale);

    expect($new->equalTo(now()->addDays(30)->addYears(2)))->toBeTrue()
        ->and($stale->expires_at->equalTo($new))->toBeTrue()
        ->and($tag->refresh()->expires_at->equalTo($new))->toBeTrue();
});

it('shortens from the current database value even with a stale model', function () {
    $tag = tagFixture(['expires_at' => now()->addYears(2), 'first_activated_at' => now()->subDay()]);
    $stale = Tag::find($tag->id);
    $svc = new SubscriptionService;

    $svc->shortenOneYear($tag);
    $svc->shortenOneYear($stale);

    expect($tag->refresh()->expires_at->equalTo(now()))->toBeTrue();
});

it('refuses to extend a never-activated tag', function () {
    $tag = tagFixture();
    expect(fn () => (new SubscriptionService)->extendOneYear($tag))->toThrow(LogicException::class);
    expect($tag->refresh()->expires_at)->toBeNull();
});

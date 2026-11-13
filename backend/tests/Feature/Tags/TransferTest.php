<?php

use App\Exceptions\TagAlreadyClaimed;
use App\Models\Car;
use App\Models\User;
use App\Services\Tags\SubscriptionService;
use App\Services\Tags\TagClaimer;
use App\Services\Tags\TagTransfer;

beforeEach(fn () => $this->travelTo(now()->startOfSecond()));

it('does not reset dates on unlink then reclaim', function () {
    $tag = tagFixture();
    app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs());
    $tag->refresh();
    [$first, $expires] = [$tag->first_activated_at, $tag->expires_at];

    $this->travel(10)->days();
    app(TagTransfer::class)->unlink($tag);
    app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs());

    $tag->refresh();
    expect($tag->first_activated_at->equalTo($first))->toBeTrue()
        ->and($tag->expires_at->equalTo($expires))->toBeTrue();
});

it('does not grant a new year when reclaiming an expired tag', function () {
    $tag = tagFixture();
    app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs());
    $this->travel(400)->days();
    app(TagTransfer::class)->unlink($tag->refresh());
    app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs());

    expect(app(SubscriptionService::class)->isActive($tag->refresh()))->toBeFalse();
});

it('keeps the car with the old owner on unlink', function () {
    $owner = User::factory()->create();
    $tag = tagFixture();
    $car = app(TagClaimer::class)->claim($owner, $tag->token, carAttrs());

    app(TagTransfer::class)->unlink($tag);

    expect($tag->refresh()->car_id)->toBeNull()
        ->and(Car::find($car->id)->user_id)->toBe($owner->id);
});

it('refuses a different user while the tag is still linked', function () {
    $tag = tagFixture();
    app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs());
    expect(fn () => app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs()))
        ->toThrow(TagAlreadyClaimed::class);
});

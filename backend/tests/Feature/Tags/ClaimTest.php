<?php

use App\Exceptions\CarAlreadyLinked;
use App\Exceptions\TagAlreadyClaimed;
use App\Exceptions\TagNotFound;
use App\Exceptions\TagRevoked;
use App\Models\Car;
use App\Models\User;
use App\Services\Tags\TagClaimer;
use App\Services\Tags\TokenGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(now()->startOfSecond()));

it('generates 16 distinct base62 tokens', function () {
    $g = new TokenGenerator;
    $a = $g->generate();
    expect($a)->toMatch('/^[0-9A-Za-z]{16}$/')->and($g->generate())->not->toBe($a);
});

it('sets first_activated_at now and expires_at exactly one year later on first claim', function () {
    $tag = tagFixture();
    app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs());

    $tag->refresh();
    expect($tag->first_activated_at->equalTo(now()))->toBeTrue()
        ->and($tag->expires_at->equalTo(now()->addYear()))->toBeTrue()
        ->and($tag->car_id)->not->toBeNull();
});

it('marks an unsold tag sold at claim', function () {
    $tag = tagFixture();
    app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs());
    expect($tag->refresh()->sold_at->equalTo(now()))->toBeTrue();
});

it('keeps an existing sold_at', function () {
    $sold = now()->subMonths(2);
    $tag = tagFixture(['sold_at' => $sold]);
    app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs());
    expect($tag->refresh()->sold_at->equalTo($sold))->toBeTrue();
});

it('lets only one of two concurrent claims win', function () {
    $tag = tagFixture();
    $a = User::factory()->create();
    $b = User::factory()->create();

    // The claim reads the tag row under a lock inside a transaction.
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });
    app(TagClaimer::class)->claim($a, $tag->token, carAttrs());
    expect(collect($queries)->contains(fn ($s) => str_contains($s, 'from `tags`') && str_contains($s, 'for update')))->toBeTrue();

    // The loser, arriving after the winner committed, is refused and creates nothing.
    expect(fn () => app(TagClaimer::class)->claim($b, $tag->token, carAttrs()))
        ->toThrow(TagAlreadyClaimed::class);
    expect(Car::count())->toBe(1);

    // The database also refuses a second tag on the same car.
    $other = tagFixture();
    expect(fn () => $other->update(['car_id' => $tag->refresh()->car_id]))
        ->toThrow(QueryException::class);
});

it('exposes stable error codes', function () {
    expect((new TagAlreadyClaimed)->errorCode)->toBe('tag_already_claimed')
        ->and((new TagNotFound)->errorCode)->toBe('tag_not_found')
        ->and((new TagRevoked)->errorCode)->toBe('tag_revoked')
        ->and((new CarAlreadyLinked)->errorCode)->toBe('car_already_linked');
});

it('is idempotent for the same user retrying', function () {
    $tag = tagFixture();
    $user = User::factory()->create();
    $first = app(TagClaimer::class)->claim($user, $tag->token, carAttrs());
    $expires = $tag->refresh()->expires_at;

    $this->travel(5)->minutes();
    $again = app(TagClaimer::class)->claim($user, $tag->token, carAttrs());

    expect($again->id)->toBe($first->id)->and(Car::count())->toBe(1)
        ->and($tag->refresh()->expires_at->equalTo($expires))->toBeTrue();
});

it('refuses a revoked tag', function () {
    $tag = tagFixture(['revoked_at' => now()]);
    expect(fn () => app(TagClaimer::class)->claim(User::factory()->create(), $tag->token, carAttrs()))
        ->toThrow(TagRevoked::class);
});

it('refuses an unknown token', function () {
    expect(fn () => app(TagClaimer::class)->claim(User::factory()->create(), 'doesNotExist0000', carAttrs()))
        ->toThrow(TagNotFound::class);
});

it('treats tokens as case sensitive', function () {
    tagFixture(['token' => 'AbCdEfGh12345678']);
    expect(fn () => app(TagClaimer::class)->claim(User::factory()->create(), 'aBcDeFgH12345678', carAttrs()))
        ->toThrow(TagNotFound::class);
});

it('refuses to link a car that already has a tag', function () {
    $user = User::factory()->create();
    $first = tagFixture();
    $car = app(TagClaimer::class)->claim($user, $first->token, carAttrs());
    $second = tagFixture();

    expect(fn () => app(TagClaimer::class)->claim($user, $second->token, [], $car))
        ->toThrow(CarAlreadyLinked::class);
    expect($second->refresh()->car_id)->toBeNull();
});

it('links an existing unlinked car of the same user', function () {
    $user = User::factory()->create();
    $car = Car::create(['user_id' => $user->id] + carAttrs());
    $tag = tagFixture();

    expect(app(TagClaimer::class)->claim($user, $tag->token, [], $car)->id)->toBe($car->id)
        ->and($tag->refresh()->car_id)->toBe($car->id);
});

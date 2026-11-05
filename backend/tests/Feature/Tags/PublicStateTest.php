<?php

use App\Enums\PublicTagState;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\PublicTagStateResolver;
use App\Services\Tags\TagClaimer;

beforeEach(fn () => $this->travelTo(now()->startOfSecond()));

function claimedTag(?User $user = null): Tag
{
    $tag = tagFixture();
    app(TagClaimer::class)->claim($user ?? User::factory()->create(), $tag->token, carAttrs());

    return $tag->refresh();
}

function stateOf(Tag $tag): PublicTagState
{
    return app(PublicTagStateResolver::class)->resolve($tag->fresh());
}

it('is Unclaimed for a tag with no car', function () {
    expect(stateOf(tagFixture()))->toBe(PublicTagState::Unclaimed);
});

it('is Unclaimed for an unlinked tag whatever its expiry', function () {
    expect(stateOf(tagFixture(['expires_at' => now()->subYear()])))->toBe(PublicTagState::Unclaimed);
});

it('is Active for a paid, visible car', function () {
    expect(stateOf(claimedTag()))->toBe(PublicTagState::Active);
});

it('is Neutral when expired', function () {
    $tag = claimedTag();
    $this->travelTo($tag->expires_at);
    expect(stateOf($tag))->toBe(PublicTagState::Neutral);
});

it('is Neutral when stealthed', function () {
    $tag = claimedTag();
    $tag->car->update(['stealth_enabled' => true]);
    expect(stateOf($tag))->toBe(PublicTagState::Neutral);
});

it('is Neutral when revoked, even without a car', function () {
    expect(stateOf(tagFixture(['revoked_at' => now()])))->toBe(PublicTagState::Neutral);
    $tag = claimedTag();
    $tag->update(['revoked_at' => now()]);
    expect(stateOf($tag))->toBe(PublicTagState::Neutral);
});

it('is Neutral when the owner is suspended', function () {
    $user = User::factory()->create();
    $tag = claimedTag($user);
    $user->forceFill(['suspended_at' => now()])->save();
    expect(stateOf($tag))->toBe(PublicTagState::Neutral);
});

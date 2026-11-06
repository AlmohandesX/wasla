<?php

use App\Models\Car;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagClaimer;

function adminUser(array $attrs = []): User
{
    return owner(array_merge(['role' => 'admin', 'email_verified_at' => now()], $attrs));
}

/** @return array{0: Tag, 1: Car, 2: User} */
function adminClaimedTag(?User $user = null): array
{
    $user ??= owner(['email_verified_at' => now()]);
    $tag = tagFixture();
    $car = app(TagClaimer::class)->claim($user, $tag->token, carAttrs());

    return [$tag->fresh(), $car, $user];
}

<?php

use App\Models\Car;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagClaimer;

/** A web panel admin. Panel accounts sign in with their username; older ones may still carry an email. */
function adminUser(array $attrs = []): User
{
    static $n = 0;
    $n++;

    return owner(array_merge(['role' => 'admin', 'username' => 'admin'.$n.'-'.bin2hex(random_bytes(3)), 'email_verified_at' => now()], $attrs));
}

function superAdminUser(array $attrs = []): User
{
    return adminUser(array_merge(['role' => 'super_admin'], $attrs));
}

/** @return array{0: Tag, 1: Car, 2: User} */
function adminClaimedTag(?User $user = null): array
{
    $user ??= owner(['email_verified_at' => now()]);
    $tag = tagFixture();
    $car = app(TagClaimer::class)->claim($user, $tag->token, carAttrs());

    return [$tag->fresh(), $car, $user];
}

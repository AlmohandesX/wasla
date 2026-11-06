<?php

namespace App\Services\Tags;

use App\Enums\PublicTagState;
use App\Models\Tag;

class PublicTagStateResolver
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function resolve(Tag $tag): PublicTagState
    {
        if ($tag->isRevoked()) {
            return PublicTagState::Neutral;
        }

        $car = $tag->car;
        if ($car === null) {
            return PublicTagState::Unclaimed;
        }

        if ($car->stealth_enabled
            || $car->user->isSuspended()
            || ! $this->subscriptions->isActive($tag)) {
            return PublicTagState::Neutral;
        }

        return PublicTagState::Active;
    }
}

<?php

namespace App\Services\Tags;

use App\Models\Tag;
use Illuminate\Support\Carbon;

class SubscriptionService
{
    public function isActive(Tag $tag): bool
    {
        return $tag->expires_at !== null && now()->lt($tag->expires_at);
    }

    /** Renewing early extends from expiry; renewing after expiry starts from now. */
    public function extendOneYear(Tag $tag): Carbon
    {
        $base = $tag->expires_at !== null && $tag->expires_at->gt(now()) ? $tag->expires_at->copy() : now();
        $tag->update(['expires_at' => $base->addYear()]);

        return $tag->expires_at->copy();
    }

    public function shortenOneYear(Tag $tag): Carbon
    {
        if ($tag->expires_at === null) {
            throw new \LogicException('Tag has never been activated.');
        }
        $tag->update(['expires_at' => $tag->expires_at->copy()->subYear()]);

        return $tag->expires_at->copy();
    }
}

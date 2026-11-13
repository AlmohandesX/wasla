<?php

namespace App\Services\Tags;

use App\Models\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function isActive(Tag $tag): bool
    {
        return $tag->expires_at !== null && now()->lt($tag->expires_at);
    }

    /** Renewing early extends from expiry; renewing after expiry starts from now. */
    public function extendOneYear(Tag $tag): Carbon
    {
        return $this->withLockedTag($tag, function (Tag $fresh) {
            if ($fresh->first_activated_at === null) {
                throw new \LogicException('Tag has never been activated.');
            }
            $base = $fresh->expires_at !== null && $fresh->expires_at->gt(now()) ? $fresh->expires_at->copy() : now();
            $fresh->update(['expires_at' => $base->addYear()]);
        });
    }

    public function shortenOneYear(Tag $tag): Carbon
    {
        return $this->withLockedTag($tag, function (Tag $fresh) {
            if ($fresh->expires_at === null) {
                throw new \LogicException('Tag has never been activated.');
            }
            $fresh->update(['expires_at' => $fresh->expires_at->copy()->subYear()]);
        });
    }

    /** Re-reads the tag under a row lock so concurrent or stale callers never overwrite each other. */
    private function withLockedTag(Tag $tag, callable $change): Carbon
    {
        return DB::transaction(function () use ($tag, $change) {
            $fresh = Tag::query()->lockForUpdate()->findOrFail($tag->id);
            $change($fresh);
            $tag->setRawAttributes($fresh->getAttributes(), true);

            return $fresh->expires_at->copy();
        });
    }
}

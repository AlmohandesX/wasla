<?php

namespace App\Services\Tags;

use App\Exceptions\AdminActionException;
use App\Exceptions\InvalidReplacementTag;
use App\Models\Tag;
use App\Models\User;
use App\Services\Concerns\AuditsAdminActions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TagAdminService
{
    use AuditsAdminActions;

    /** Moves the car and the exact remaining subscription to a fresh tag and permanently revokes the old one. */
    public function replace(Tag $old, Tag $new, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        DB::transaction(function () use ($old, $new, $admin, $reason) {
            [$lockedOld, $lockedNew] = $this->lockPair($old, $new);

            if ($lockedOld->id === $lockedNew->id || $lockedOld->isRevoked() || $lockedOld->car_id === null) {
                throw InvalidReplacementTag::because('old_tag_not_replaceable');
            }
            // A fresh tag only: anything else would hand out a free year or overwrite someone else's tag.
            if ($lockedNew->car_id !== null || $lockedNew->first_activated_at !== null
                || $lockedNew->expires_at !== null || $lockedNew->isRevoked()) {
                throw InvalidReplacementTag::because('invalid_replacement');
            }

            $carId = $lockedOld->car_id;
            $meta = [
                'old_tag_id' => $lockedOld->id,
                'new_tag_id' => $lockedNew->id,
                'car_id' => $carId,
                'expires_at' => $lockedOld->expires_at?->toIso8601String(),
            ];

            // The old tag lets go of the car first so the car never holds two tags.
            $lockedOld->forceFill(['car_id' => null, 'revoked_at' => now(), 'replaced_by_tag_id' => $lockedNew->id])->save();
            $lockedNew->forceFill([
                'car_id' => $carId,
                'sold_at' => $lockedNew->sold_at ?? now(),
                'first_activated_at' => $lockedOld->first_activated_at,
                'expires_at' => $lockedOld->expires_at,
            ])->save();

            $this->audit($admin, 'tag.replace', 'tag', $lockedOld->id, $reason, $meta);

            $old->setRawAttributes($lockedOld->getAttributes(), true);
            $new->setRawAttributes($lockedNew->getAttributes(), true);
        });
    }

    /** Permanently revokes the tag and lets go of its car, as replace does, so the owner sees the car unlinked. */
    public function revoke(Tag $tag, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        $this->withLockedTag($tag, function (Tag $fresh) use ($admin, $reason) {
            $carId = $fresh->car_id;
            $fresh->forceFill(['car_id' => null, 'revoked_at' => $fresh->revoked_at ?? now()])->save();
            $this->audit($admin, 'tag.revoke', 'tag', $fresh->id, $reason, ['car_id' => $carId]);
        });
    }

    /** Detaches the tag from its car so it can be claimed again. Expiry is untouched. */
    public function forceRelease(Tag $tag, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        $this->withLockedTag($tag, function (Tag $fresh) use ($admin, $reason) {
            $carId = $fresh->car_id;
            $fresh->forceFill(['car_id' => null])->save();
            $this->audit($admin, 'tag.force_release', 'tag', $fresh->id, $reason, ['car_id' => $carId]);
        });
    }

    public function extendManually(Tag $tag, Carbon $newExpiry, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        $this->withLockedTag($tag, function (Tag $fresh) use ($newExpiry, $admin, $reason) {
            if ($fresh->isRevoked()) {
                throw AdminActionException::because('tag_revoked');
            }
            if ($fresh->first_activated_at === null) {
                throw AdminActionException::because('tag_never_activated');
            }
            if ($newExpiry->lte(now())) {
                throw AdminActionException::because('expiry_in_past');
            }
            $previous = $fresh->expires_at?->toIso8601String();
            $fresh->forceFill(['expires_at' => $newExpiry->copy()->utc()])->save();
            $this->audit($admin, 'tag.extend', 'tag', $fresh->id, $reason, ['from' => $previous, 'to' => $fresh->expires_at->toIso8601String()]);
        });
    }

    /** Re-reads the tag under a row lock and syncs the caller's instance afterwards. */
    private function withLockedTag(Tag $tag, callable $change): void
    {
        DB::transaction(function () use ($tag, $change) {
            $fresh = Tag::query()->lockForUpdate()->findOrFail($tag->id);
            $change($fresh);
            $tag->setRawAttributes($fresh->getAttributes(), true);
        });
    }

    /** Locks both rows in id order so two concurrent operations cannot deadlock. */
    private function lockPair(Tag $a, Tag $b): array
    {
        $rows = Tag::query()->whereIn('id', [$a->id, $b->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$rows[$a->id], $rows[$b->id]];
    }
}

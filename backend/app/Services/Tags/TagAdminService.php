<?php

namespace App\Services\Tags;

use App\Exceptions\InvalidReplacementTag;
use App\Models\AuditLog;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TagAdminService
{
    /** Moves the car and the exact remaining subscription to a fresh tag and permanently revokes the old one. */
    public function replace(Tag $old, Tag $new, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        DB::transaction(function () use ($old, $new, $admin, $reason) {
            [$lockedOld, $lockedNew] = $this->lockPair($old, $new);

            if ($lockedOld->id === $lockedNew->id || $lockedOld->isRevoked() || $lockedOld->car_id === null) {
                throw new InvalidReplacementTag('The old tag must be linked and not revoked.');
            }
            // A fresh tag only: anything else would hand out a free year or overwrite someone's tag.
            if ($lockedNew->car_id !== null || $lockedNew->first_activated_at !== null
                || $lockedNew->expires_at !== null || $lockedNew->isRevoked()) {
                throw new InvalidReplacementTag('The replacement tag must be unused.');
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

            $this->audit($admin, 'tag.replace', $lockedOld, $reason, $meta);

            $old->setRawAttributes($lockedOld->getAttributes(), true);
            $new->setRawAttributes($lockedNew->getAttributes(), true);
        });
    }

    public function revoke(Tag $tag, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        $this->withLockedTag($tag, function (Tag $fresh) use ($admin, $reason) {
            $fresh->forceFill(['revoked_at' => $fresh->revoked_at ?? now()])->save();
            $this->audit($admin, 'tag.revoke', $fresh, $reason, ['car_id' => $fresh->car_id]);
        });
    }

    /** Detaches the tag from its car so it can be claimed again. Expiry is untouched. */
    public function forceRelease(Tag $tag, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        $this->withLockedTag($tag, function (Tag $fresh) use ($admin, $reason) {
            $carId = $fresh->car_id;
            $fresh->forceFill(['car_id' => null])->save();
            $this->audit($admin, 'tag.force_release', $fresh, $reason, ['car_id' => $carId]);
        });
    }

    public function extendManually(Tag $tag, Carbon $newExpiry, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        $this->withLockedTag($tag, function (Tag $fresh) use ($newExpiry, $admin, $reason) {
            if ($fresh->first_activated_at === null) {
                throw new \LogicException('Tag has never been activated.');
            }
            $previous = $fresh->expires_at?->toIso8601String();
            $fresh->forceFill(['expires_at' => $newExpiry->copy()->utc()])->save();
            $this->audit($admin, 'tag.extend', $fresh, $reason, ['from' => $previous, 'to' => $fresh->expires_at->toIso8601String()]);
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

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return $reason;
    }

    private function audit(User $admin, string $action, Tag $tag, string $reason, array $meta): void
    {
        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => $action,
            'subject_type' => 'tag',
            'subject_id' => $tag->id,
            'meta' => $meta + ['reason' => $reason],
        ]);
    }
}

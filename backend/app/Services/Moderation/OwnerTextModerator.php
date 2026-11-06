<?php

namespace App\Services\Moderation;

use App\Exceptions\MessageBlocked;
use App\Exceptions\ModerationLimitReached;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/** Moderates text an owner writes (status, default message, contact label); throws when blocked. */
class OwnerTextModerator
{
    public function __construct(private readonly ModerationPipeline $pipeline) {}

    /** @param 'status'|'default_message'|'contact_label' $surface */
    public function assertAllowed(User $user, string $text, string $surface): void
    {
        $this->spendBudget($user);

        $result = $this->pipeline->check($text, ['surface' => $surface, 'user_id' => $user->id]);

        if ($result->blocked()) {
            throw new MessageBlocked;
        }
    }

    /**
     * One owner may not use up the platform's daily provider cap for everyone. Past their own (Istanbul) daily budget
     * the write is refused rather than let through on the blacklist alone.
     */
    private function spendBudget(User $user): void
    {
        $key = 'moderation:owner:'.$user->id.':'.now(config('wasla.display_timezone'))->toDateString();
        Cache::add($key, 0, now()->addDays(2));

        if (Cache::increment($key) > (int) config('wasla.moderation.owner_daily_cap')) {
            throw new ModerationLimitReached;
        }
    }
}

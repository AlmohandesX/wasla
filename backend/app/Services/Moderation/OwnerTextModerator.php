<?php

namespace App\Services\Moderation;

use App\Exceptions\MessageBlocked;
use App\Models\User;

/** Moderates text an owner writes (status, default message, contact label); throws when blocked. */
class OwnerTextModerator
{
    public function __construct(private readonly ModerationPipeline $pipeline) {}

    /** @param 'status'|'default_message'|'contact_label' $surface */
    public function assertAllowed(User $user, string $text, string $surface): void
    {
        $result = $this->pipeline->check($text, ['surface' => $surface, 'user_id' => $user->id]);

        if ($result->blocked()) {
            throw new MessageBlocked;
        }
    }
}

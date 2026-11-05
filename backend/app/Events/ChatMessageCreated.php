<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Foundation\Events\Dispatchable;

/** A chat message passed moderation and was stored, from either side. Listened to for push notifications. */
class ChatMessageCreated
{
    use Dispatchable;

    public function __construct(public readonly ChatMessage $message) {}
}

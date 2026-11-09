<?php

namespace App\Policies;

use App\Models\ChatMessage;
use App\Models\User;

class ChatMessagePolicy
{
    /** An owner reports what visitors wrote in their car's chats, never their own replies. */
    public function report(User $user, ChatMessage $message): bool
    {
        return $message->sender === 'visitor' && $message->thread->car->user_id === $user->id;
    }
}

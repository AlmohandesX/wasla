<?php

namespace App\Policies;

use App\Models\ChatMessage;
use App\Models\User;

class ChatMessagePolicy
{
    public function report(User $user, ChatMessage $message): bool
    {
        return $message->thread->car->user_id === $user->id;
    }
}

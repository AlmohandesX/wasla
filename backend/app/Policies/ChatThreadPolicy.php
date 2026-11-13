<?php

namespace App\Policies;

use App\Models\ChatThread;
use App\Models\User;

/** Access follows the car's current owner, so a thread stays with the owner it happened under. */
class ChatThreadPolicy
{
    public function view(User $user, ChatThread $thread): bool
    {
        return $thread->car->user_id === $user->id;
    }

    public function reply(User $user, ChatThread $thread): bool
    {
        return $this->view($user, $thread);
    }

    public function block(User $user, ChatThread $thread): bool
    {
        return $this->view($user, $thread);
    }
}

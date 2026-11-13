<?php

namespace App\Policies;

use App\Models\DeviceToken;
use App\Models\User;

class DeviceTokenPolicy
{
    public function delete(User $user, DeviceToken $token): bool
    {
        return $token->user_id === $user->id;
    }
}

<?php

namespace App\Policies;

use App\Models\EmergencyContact;
use App\Models\User;

class EmergencyContactPolicy
{
    public function update(User $user, EmergencyContact $contact): bool
    {
        return $contact->car->user_id === $user->id;
    }

    public function delete(User $user, EmergencyContact $contact): bool
    {
        return $this->update($user, $contact);
    }
}

<?php

namespace App\Policies;

use App\Models\Car;
use App\Models\User;

class CarPolicy
{
    public function view(User $user, Car $car): bool
    {
        return $car->user_id === $user->id;
    }

    public function update(User $user, Car $car): bool
    {
        return $car->user_id === $user->id;
    }

    public function delete(User $user, Car $car): bool
    {
        return $car->user_id === $user->id;
    }
}

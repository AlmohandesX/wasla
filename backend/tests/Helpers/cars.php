<?php

use App\Models\Car;
use App\Models\User;

/** A car for $user, linked to an activated tag that expires at $expiresAt (default: in a year). */
function linkedCar(User $user, ?DateTimeInterface $expiresAt = null, array $carAttrs = []): Car
{
    $car = Car::create(array_merge(carAttrs(), ['user_id' => $user->id], $carAttrs));
    tagFixture([
        'car_id' => $car->id,
        'first_activated_at' => now()->subDay(),
        'expires_at' => $expiresAt ?? now()->addYear(),
    ]);

    return $car;
}

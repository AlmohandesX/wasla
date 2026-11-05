<?php

namespace App\Services\Tags;

use App\Exceptions\CarAlreadyLinked;
use App\Exceptions\CarNotOwned;
use App\Exceptions\TagAlreadyClaimed;
use App\Exceptions\TagNotFound;
use App\Exceptions\TagRevoked;
use App\Models\Car;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TagClaimer
{
    public function claim(User $user, string $token, array $carAttributes, ?Car $existingCar = null): Car
    {
        return DB::transaction(function () use ($user, $token, $carAttributes, $existingCar) {
            $tag = Tag::query()->where('token', $token)->lockForUpdate()->first()
                ?? throw new TagNotFound;

            if ($tag->isRevoked()) {
                throw new TagRevoked;
            }

            if ($tag->car_id !== null) {
                $linked = Car::findOrFail($tag->car_id);
                if ($linked->user_id === $user->id) {
                    return $linked; // idempotent retry
                }
                throw new TagAlreadyClaimed;
            }

            if ($existingCar !== null) {
                if ($existingCar->user_id !== $user->id) {
                    throw new CarNotOwned;
                }
                if (Tag::where('car_id', $existingCar->id)->exists()) {
                    throw new CarAlreadyLinked;
                }
                $car = $existingCar;
            } else {
                $car = Car::create(['user_id' => $user->id] + $carAttributes);
            }

            $tag->car_id = $car->id;
            $tag->sold_at ??= now();
            if ($tag->first_activated_at === null) {
                $tag->first_activated_at = now();
                $tag->expires_at = now()->addYear();
            }
            $tag->save();

            return $car;
        });
    }
}

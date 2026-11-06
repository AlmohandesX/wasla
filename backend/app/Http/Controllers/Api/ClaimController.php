<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CarNotOwned;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\ClaimTagRequest;
use App\Http\Resources\CarResource;
use App\Models\Car;
use App\Services\Tags\TagClaimer;

class ClaimController extends Controller
{
    public function __invoke(ClaimTagRequest $request, TagClaimer $claimer): CarResource
    {
        $existing = null;
        if ($request->filled('car_id')) {
            // A missing car answers like someone else's car, so ids cannot be probed.
            $existing = Car::find($request->integer('car_id')) ?? throw new CarNotOwned;
        }

        $car = $claimer->claim(
            $request->user(),
            $request->string('token')->toString(),
            $request->safe()->only(['model', 'plate']),
            $existing,
        );

        return new CarResource($car->load('tag'));
    }
}

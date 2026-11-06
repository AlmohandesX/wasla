<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\OwnerRequest;
use App\Http\Requests\Cars\StoreReminderRequest;
use App\Http\Requests\Cars\UpdateReminderRequest;
use App\Http\Resources\ReminderResource;
use App\Models\Car;
use App\Models\Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ReminderController extends Controller
{
    public function index(Request $request, Car $car): JsonResponse
    {
        Gate::authorize('view', $car);

        return response()->json(['data' => ReminderResource::collection($car->reminders)->resolve($request)]);
    }

    public function store(StoreReminderRequest $request, Car $car): JsonResponse
    {
        $reminder = $car->reminders()->create($request->safe()->only(['type', 'due_on']));

        return (new ReminderResource($reminder))->response()->setStatusCode(201);
    }

    public function update(UpdateReminderRequest $request, Reminder $reminder): ReminderResource
    {
        $reminder->update($request->safe()->only(['type', 'due_on']));

        return new ReminderResource($reminder);
    }

    public function destroy(OwnerRequest $request, Reminder $reminder): Response
    {
        $reminder->delete();

        return response()->noContent();
    }
}

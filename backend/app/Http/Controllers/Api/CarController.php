<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\DestroyCarRequest;
use App\Http\Requests\Cars\StoreCarRequest;
use App\Http\Requests\Cars\UpdateCarRequest;
use App\Http\Resources\CarResource;
use App\Models\Car;
use App\Services\Cars\CarPhotoStore;
use App\Services\Moderation\OwnerTextModerator;
use App\Services\Tags\TagTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class CarController extends Controller
{
    public function __construct(private readonly OwnerTextModerator $moderator) {}

    public function index(Request $request): JsonResponse
    {
        $cars = $request->user()->cars()->with('tag')->orderBy('id')->get();

        return response()->json(['data' => CarResource::collection($cars)->resolve($request)]);
    }

    public function show(Request $request, Car $car): CarResource
    {
        Gate::authorize('view', $car);

        return new CarResource($car->load('tag'));
    }

    public function store(StoreCarRequest $request): JsonResponse
    {
        $attributes = $request->safe()->only(['model', 'plate', 'default_message']);
        $this->moderateDefaultMessage($request, $attributes);

        $car = Car::create(['user_id' => $request->user()->id] + $attributes);

        return (new CarResource($car->load('tag')))->response()->setStatusCode(201);
    }

    public function update(UpdateCarRequest $request, Car $car): CarResource
    {
        $attributes = $request->safe()->only(['model', 'plate', 'default_message']);
        $this->moderateDefaultMessage($request, $attributes);

        $car->update($attributes);

        return new CarResource($car->load('tag'));
    }

    /** Deletes the car and releases its tag with the dates kept; the photo file goes only once the delete committed. */
    public function destroy(DestroyCarRequest $request, Car $car, TagTransfer $transfer, CarPhotoStore $photos): Response
    {
        $disk = $car->photo_disk;
        $path = $car->photo_path;

        DB::transaction(function () use ($car, $transfer) {
            if ($car->tag !== null) {
                $transfer->unlink($car->tag);
            }
            $car->delete();
        });

        if ($disk !== null && $path !== null && ! $photos->deleteFile($disk, $path)) {
            // The disk name only: never the path, which could name the car.
            Log::warning('A car photo could not be deleted after the car was removed.', ['disk' => $disk]);
        }

        return response()->noContent();
    }

    private function moderateDefaultMessage(Request $request, array $attributes): void
    {
        if (($attributes['default_message'] ?? null) !== null) {
            $this->moderator->assertAllowed($request->user(), $attributes['default_message'], 'default_message');
        }
    }
}

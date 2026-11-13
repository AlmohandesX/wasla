<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\OwnerRequest;
use App\Http\Requests\Cars\StoreCarPhotoRequest;
use App\Models\Car;
use App\Services\Cars\CarPhotoStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CarPhotoController extends Controller
{
    public function __construct(private readonly CarPhotoStore $photos) {}

    public function store(StoreCarPhotoRequest $request, Car $car): JsonResponse
    {
        $this->photos->store($car, $request->file('photo'));

        return response()->json(['has_photo' => true]);
    }

    /** Streams the file through the API to the owner only; the storage path is never exposed. */
    public function show(Request $request, Car $car): StreamedResponse
    {
        Gate::authorize('view', $car);
        abort_if($car->photo_path === null || ! Storage::disk($car->photo_disk)->exists($car->photo_path), 404);

        return Storage::disk($car->photo_disk)->response($car->photo_path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(OwnerRequest $request, Car $car): Response
    {
        $this->photos->delete($car);

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\OwnerRequest;
use App\Http\Requests\Cars\StoreContactRequest;
use App\Http\Requests\Cars\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Car;
use App\Models\EmergencyContact;
use App\Services\Moderation\OwnerTextModerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ContactController extends Controller
{
    public function __construct(private readonly OwnerTextModerator $moderator) {}

    public function index(Request $request, Car $car): JsonResponse
    {
        Gate::authorize('view', $car);

        return response()->json(['data' => ContactResource::collection($car->contacts)->resolve($request)]);
    }

    public function store(StoreContactRequest $request, Car $car): JsonResponse
    {
        $data = $request->safe()->only(['label', 'phone']);
        $this->moderator->assertAllowed($request->user(), $data['label'], 'contact_label');

        $contact = $car->contacts()->create($data + ['position' => ((int) $car->contacts()->max('position')) + 1]);

        return (new ContactResource($contact))->response()->setStatusCode(201);
    }

    public function update(UpdateContactRequest $request, EmergencyContact $contact): ContactResource
    {
        $data = $request->safe()->only(['label', 'phone', 'position']);
        if (isset($data['label'])) {
            $this->moderator->assertAllowed($request->user(), $data['label'], 'contact_label');
        }

        $contact->update($data);

        return new ContactResource($contact);
    }

    public function destroy(OwnerRequest $request, EmergencyContact $contact): Response
    {
        $contact->delete();

        return response()->noContent();
    }
}

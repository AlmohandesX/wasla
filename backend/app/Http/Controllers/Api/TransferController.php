<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\OwnerRequest;
use App\Http\Resources\CarResource;
use App\Models\Car;
use App\Services\Tags\TagTransfer;

class TransferController extends Controller
{
    /** Works on an expired car too: unlinking is always reachable. */
    public function unlink(OwnerRequest $request, Car $car, TagTransfer $transfer): CarResource
    {
        if ($car->tag !== null) {
            $transfer->unlink($car->tag);
        }

        return new CarResource($car->unsetRelation('tag')->load('tag'));
    }
}

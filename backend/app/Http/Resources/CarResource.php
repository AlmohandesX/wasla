<?php

namespace App\Http\Resources;

use App\Models\Car;
use App\Services\Tags\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var Car $car */
        $car = $this->resource;
        $tag = $car->tag;

        return [
            'id' => $car->id,
            'model' => $car->model,
            'plate' => $car->plate,
            'default_message' => $car->default_message,
            'current_message' => $car->currentMessage(),
            'status' => $car->hasActiveStatus()
                ? ['text' => $car->statusText(), 'preset' => $car->status_key, 'expires_at' => $car->status_expires_at->toIso8601ZuluString()]
                : null,
            'stealth_enabled' => $car->stealth_enabled,
            'chats_paused' => $car->chats_paused,
            'linked' => $tag !== null,
            // Printed next to the QR on the sticker sheet. The owner quotes it to replace or revoke a lost tag;
            // the QR itself only carries the secret token, which is never sent back.
            'serial_number' => $tag?->serial,
            'subscription' => [
                'active' => $tag !== null && app(SubscriptionService::class)->isActive($tag),
                'expires_at' => $tag?->expires_at?->toIso8601ZuluString(),
            ],
        ];
    }
}

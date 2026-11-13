<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** No visitor identity and no owner contact data: a thread is only its car, state and last message. */
class ChatThreadResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'car_id' => $this->car_id,
            'blocked' => $this->isBlocked(),
            'unread_count' => (int) ($this->unread_count ?? 0),
            'last_message' => $this->lastMessage ? (new ChatMessageResource($this->lastMessage))->resolve($request) : null,
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

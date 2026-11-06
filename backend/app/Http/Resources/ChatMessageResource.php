<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'sender' => $this->sender,
            'body' => $this->body,
            'read' => $this->read_at !== null,
            'reported' => $this->reported_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

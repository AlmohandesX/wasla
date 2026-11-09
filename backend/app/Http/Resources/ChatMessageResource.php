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
            // A message a moderator removed keeps its place in the thread, without its text.
            'body' => $this->isHidden() ? '' : $this->body,
            'hidden' => $this->isHidden(),
            'read' => $this->read_at !== null,
            // Lists load this with withExists('report as reported'); a single message falls back to a query.
            'reported' => (bool) ($this->resource->getAttribute('reported') ?? $this->resource->report()->exists()),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

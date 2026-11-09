<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** What a visitor may see of a message: nothing about read state, reports, moderation or thread. */
class VisitorMessageResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender' => $this->sender,
            'body' => $this->displayBody(),
            'hidden' => $this->isHidden(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

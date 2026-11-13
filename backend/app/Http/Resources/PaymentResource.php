<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Status only. Billing fields and the provider reference never leave the server. */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'amount_kurus' => $this->amount_kurus,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}

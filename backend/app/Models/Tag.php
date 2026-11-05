<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['wave_id', 'serial', 'token', 'car_id', 'sold_at', 'first_activated_at', 'expires_at', 'revoked_at', 'replaced_by_tag_id'])]
class Tag extends Model
{
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class);
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'first_activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}

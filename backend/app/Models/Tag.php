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

    /** TAG_BASE_URL already ends in /t, so the printed URL is {domain}/t/{token}. */
    public function publicUrl(): string
    {
        return rtrim((string) config('wasla.tag_base_url'), '/').'/'.$this->token;
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

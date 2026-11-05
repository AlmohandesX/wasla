<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'model', 'plate', 'photo_path', 'photo_disk', 'default_message', 'stealth_enabled', 'chats_paused', 'status_text', 'status_expires_at'])]
class Car extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tag(): HasOne
    {
        return $this->hasOne(Tag::class);
    }

    protected function casts(): array
    {
        return [
            'stealth_enabled' => 'boolean',
            'chats_paused' => 'boolean',
            'status_expires_at' => 'datetime',
        ];
    }
}

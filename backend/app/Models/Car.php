<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function contacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class)->orderBy('position')->orderBy('id');
    }

    public function hasActiveStatus(): bool
    {
        return $this->status_text !== null && $this->status_expires_at?->isFuture() === true;
    }

    /** Evaluated at read time: an expired status needs no cleanup job. */
    public function currentMessage(): string
    {
        if ($this->hasActiveStatus()) {
            return $this->status_text;
        }

        return $this->default_message ?: __('wasla.default_message');
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'model', 'plate', 'photo_path', 'photo_disk', 'default_message', 'stealth_enabled', 'chats_paused', 'status_text', 'status_key', 'status_expires_at'])]
class Car extends Model
{
    /** The database defaults, so a freshly created car answers with false (not null) before it is read back. */
    protected $attributes = ['stealth_enabled' => false, 'chats_paused' => false];

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

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class)->orderBy('due_on')->orderBy('id');
    }

    public function hasActiveStatus(): bool
    {
        return $this->status_text !== null && $this->status_expires_at?->isFuture() === true;
    }

    /** The app's ready-made statuses, by key; their wording lives in lang/{locale}/wasla.php status_presets. */
    public const STATUS_PRESETS = ['back_soon', 'loading', 'at_pharmacy', 'call_me'];

    /**
     * The status in the current locale: a preset is translated, text the owner typed is shown as written.
     * Null when no status is active.
     */
    public function statusText(): ?string
    {
        if (! $this->hasActiveStatus()) {
            return null;
        }

        return in_array($this->status_key, self::STATUS_PRESETS, true)
            ? __('wasla.status_presets.'.$this->status_key)
            : $this->status_text;
    }

    /** Evaluated at read time: an expired status needs no cleanup job. */
    public function currentMessage(): string
    {
        if ($this->hasActiveStatus()) {
            return $this->statusText();
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

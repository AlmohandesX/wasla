<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['thread_id', 'sender', 'body', 'preset_key', 'moderation_status', 'read_at', 'hidden_at'])]
class ChatMessage extends Model
{
    /** The visitor's one-tap messages, in the order the scan page lists them (texts in wasla.quick_messages). */
    public const QUICK_PRESETS = ['blocking', 'alarm', 'window_open', 'lights_on', 'unlocked'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    /** Only the other side of the chat can report a message, so there is at most one report. */
    public function report(): HasOne
    {
        return $this->hasOne(MessageReport::class, 'message_id');
    }

    /** Removed by a moderator: neither side sees the text any more. */
    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isQuickMessage(): bool
    {
        return in_array($this->preset_key, self::QUICK_PRESETS, true);
    }

    /**
     * What a reader sees: a quick message in the reader's language (the current locale unless given), anything
     * typed as written, and nothing once a moderator has hidden it.
     */
    public function displayBody(?string $locale = null): string
    {
        if ($this->isHidden()) {
            return '';
        }

        return $this->isQuickMessage() ? __('wasla.quick_messages.'.$this->preset_key, [], $locale) : $this->body;
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'hidden_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['thread_id', 'sender', 'body', 'moderation_status', 'read_at', 'hidden_at'])]
class ChatMessage extends Model
{
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

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'hidden_at' => 'datetime'];
    }
}

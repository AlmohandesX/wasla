<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['thread_id', 'sender', 'body', 'moderation_status', 'read_at', 'reported_at'])]
class ChatMessage extends Model
{
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'reported_at' => 'datetime'];
    }
}

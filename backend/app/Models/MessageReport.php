<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A chat message reported by the other side of the chat: the car owner or the visitor. */
#[Fillable(['message_id', 'reporter_type', 'reporter_user_id', 'reporter_visitor_session_id', 'reviewed_at', 'reviewed_by', 'resolution'])]
class MessageReport extends Model
{
    public const RESOLUTIONS = ['dismissed', 'hidden', 'visitor_blocked', 'owner_suspended'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function reporterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reporterVisitorSession(): BelongsTo
    {
        return $this->belongsTo(VisitorSession::class, 'reporter_visitor_session_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return $this->reviewed_at === null;
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'surface', 'text', 'source', 'reason', 'thread_id', 'user_id', 'reviewed_at', 'reviewed_by'])]
class ModerationEvent extends Model
{
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}

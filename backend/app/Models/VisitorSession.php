<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['token_hash', 'ip_hash', 'last_seen_at'])]
class VisitorSession extends Model
{
    public function threads(): HasMany
    {
        return $this->hasMany(ChatThread::class);
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}

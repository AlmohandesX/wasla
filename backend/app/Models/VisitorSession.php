<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['token_hash', 'ip_hash', 'last_seen_at'])]
class VisitorSession extends Model
{
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}

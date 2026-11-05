<?php

namespace App\Models;

use App\Services\Moderation\TextNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['term', 'match_mode', 'active'])]
class BlacklistTerm extends Model
{
    protected $attributes = ['match_mode' => 'word', 'active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // The stored term is normalized exactly like incoming text.
        static::saving(fn (self $term) => $term->normalized = (new TextNormalizer)->normalize($term->term));
    }
}

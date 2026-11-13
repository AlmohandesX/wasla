<?php

namespace App\Models;

use App\Services\Moderation\TextNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['term', 'language', 'match_mode', 'active'])]
class BlacklistTerm extends Model
{
    public const LANGUAGES = ['ar', 'en', 'tr'];

    /** word: the whole word; prefix: a word starting with the term (catches suffixes); substring: anywhere. */
    public const MATCH_MODES = ['word', 'prefix', 'substring'];

    protected $attributes = ['language' => 'tr', 'match_mode' => 'word', 'active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** The stored term is normalized exactly like incoming text (a mutator, so it works without model events). */
    protected function term(): Attribute
    {
        return Attribute::make(set: fn (string $value) => [
            'term' => $value,
            'normalized' => (new TextNormalizer)->normalize($value),
        ]);
    }
}

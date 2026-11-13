<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ledger that makes a scheduled notification idempotent: one row per (kind, subject, marker). */
class SentNotification extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** True only for the caller that claimed the slot; the unique index settles races. */
    public static function claim(string $kind, int $subjectId, string $marker): bool
    {
        return static::query()->insertOrIgnore([
            'kind' => $kind, 'subject_id' => $subjectId, 'marker' => $marker, 'created_at' => now(),
        ]) === 1;
    }
}

<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tag_id', 'user_id', 'provider', 'reference', 'amount_kurus', 'currency', 'status', 'paid_at', 'refunded_at', 'flagged_at', 'flag_reason',
    'billing_name', 'billing_address', 'billing_city', 'billing_national_id', 'legal_accepted_at', 'legal_text_version'])]
#[Hidden(['billing_name', 'billing_address', 'billing_city', 'billing_national_id'])]
class Payment extends Model
{
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_kurus' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'flagged_at' => 'datetime',
            'legal_accepted_at' => 'datetime',
            'billing_name' => 'encrypted',
            'billing_address' => 'encrypted',
            'billing_city' => 'encrypted',
            'billing_national_id' => 'encrypted',
        ];
    }
}

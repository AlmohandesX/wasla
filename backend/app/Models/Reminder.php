<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['car_id', 'type', 'due_on'])]
class Reminder extends Model
{
    public const TYPES = ['oil_change', 'license_renewal'];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d'];
    }
}

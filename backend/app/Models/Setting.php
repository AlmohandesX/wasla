<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Admin-managed key/value settings, such as the renewal price. */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function value(string $key): ?string
    {
        return static::query()->whereKey($key)->value('value');
    }
}

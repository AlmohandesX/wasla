<?php

namespace App\Http\Requests\Cars;

use App\Rules\MeaningfulText;

class SetStatusRequest extends OwnerRequest
{
    public const MAX_MINUTES = 10080; // one week

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:140', new MeaningfulText],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MINUTES],
        ];
    }
}

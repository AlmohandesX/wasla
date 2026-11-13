<?php

namespace App\Http\Requests\Cars;

use App\Models\Car;
use App\Rules\MeaningfulText;
use Illuminate\Validation\Rule;

class SetStatusRequest extends OwnerRequest
{
    public const MAX_MINUTES = 10080; // one week

    public function rules(): array
    {
        return [
            // A preset needs no text: its wording comes from the server's translations, in the reader's language.
            'preset' => ['nullable', 'string', Rule::in(Car::STATUS_PRESETS)],
            'text' => ['required_without:preset', 'nullable', 'string', 'max:140', new MeaningfulText],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_MINUTES],
        ];
    }
}

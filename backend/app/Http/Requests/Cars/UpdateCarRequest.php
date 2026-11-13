<?php

namespace App\Http\Requests\Cars;

use App\Rules\MeaningfulText;

class UpdateCarRequest extends OwnerRequest
{
    public function rules(): array
    {
        return [
            'model' => ['sometimes', 'required', 'string', 'max:120', new MeaningfulText],
            'plate' => ['sometimes', 'required', 'string', 'max:32', new MeaningfulText],
            'default_message' => ['sometimes', 'nullable', 'string', 'max:280', new MeaningfulText],
        ];
    }
}

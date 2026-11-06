<?php

namespace App\Http\Requests\Cars;

use App\Rules\MeaningfulText;
use App\Rules\PhoneNumber;

class UpdateContactRequest extends StoreContactRequest
{
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:60', new MeaningfulText],
            'phone' => ['sometimes', 'required', 'string', new PhoneNumber],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** Editing a contact does not add one, so the per-car cap does not apply. */
    public function after(): array
    {
        return [];
    }
}

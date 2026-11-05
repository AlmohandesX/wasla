<?php

namespace App\Http\Requests\Cars;

use App\Rules\MeaningfulText;
use App\Rules\PhoneNumber;

class StoreContactRequest extends OwnerRequest
{
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (is_string($phone) && ($normalized = PhoneNumber::normalize($phone)) !== null) {
            $this->merge(['phone' => $normalized]);
        }
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60', new MeaningfulText],
            'phone' => ['required', 'string', new PhoneNumber],
        ];
    }
}

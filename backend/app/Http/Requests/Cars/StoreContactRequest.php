<?php

namespace App\Http\Requests\Cars;

use App\Models\Car;
use App\Rules\MeaningfulText;
use App\Rules\PhoneNumber;

class StoreContactRequest extends OwnerRequest
{
    public const MAX_PER_CAR = 5;

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

    /** Checked before the label is moderated, so a full car spends nothing. */
    public function after(): array
    {
        return [function ($validator) {
            /** @var Car $car */
            $car = $this->route('car');
            if ($car->contacts()->count() >= self::MAX_PER_CAR) {
                $validator->errors()->add('contacts', __('wasla.contacts_limit', ['max' => self::MAX_PER_CAR]));
            }
        }];
    }
}

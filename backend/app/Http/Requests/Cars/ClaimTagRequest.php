<?php

namespace App\Http\Requests\Cars;

use App\Rules\MeaningfulText;
use Illuminate\Foundation\Http\FormRequest;

class ClaimTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'car_id' => ['nullable', 'integer'],
            'model' => ['exclude_with:car_id', 'required_without:car_id', 'string', 'max:120', new MeaningfulText],
            'plate' => ['exclude_with:car_id', 'required_without:car_id', 'string', 'max:32', new MeaningfulText],
        ];
    }
}

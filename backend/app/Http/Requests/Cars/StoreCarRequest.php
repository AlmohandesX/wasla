<?php

namespace App\Http\Requests\Cars;

use App\Rules\MeaningfulText;
use Illuminate\Foundation\Http\FormRequest;

class StoreCarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'model' => ['required', 'string', 'max:120', new MeaningfulText],
            'plate' => ['required', 'string', 'max:32', new MeaningfulText],
            'default_message' => ['nullable', 'string', 'max:280', new MeaningfulText],
        ];
    }
}

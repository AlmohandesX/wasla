<?php

namespace App\Http\Requests\Cars;

use App\Rules\MeaningfulText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClaimTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // A null or empty car_id means "new car": model and plate are then required and kept.
        $dropWhenLinking = Rule::excludeIf(fn () => $this->filled('car_id'));

        return [
            'token' => ['required', 'string', 'max:64'],
            'car_id' => ['nullable', 'integer'],
            'model' => [$dropWhenLinking, 'required_without:car_id', 'string', 'max:120', new MeaningfulText],
            'plate' => [$dropWhenLinking, 'required_without:car_id', 'string', 'max:32', new MeaningfulText],
        ];
    }
}

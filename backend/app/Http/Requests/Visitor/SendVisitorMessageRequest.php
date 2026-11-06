<?php

namespace App\Http\Requests\Visitor;

use App\Rules\MeaningfulText;
use Illuminate\Foundation\Http\FormRequest;

class SendVisitorMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Anonymous by design; the controller checks the tag state and the session.
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:500', new MeaningfulText],
            'consent' => ['sometimes', 'boolean'],
        ];
    }
}

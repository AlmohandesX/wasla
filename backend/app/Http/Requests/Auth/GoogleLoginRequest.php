<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:4096'],
            'locale' => ['sometimes', Rule::in(['tr', 'en'])],
            // Only needed the first time, when the account is created: the server answers privacy_consent_required.
            'privacy_consent' => ['sometimes', 'boolean'],
        ];
    }
}

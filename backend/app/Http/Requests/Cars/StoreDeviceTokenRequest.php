<?php

namespace App\Http\Requests\Cars;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The signed-in user registers a device of their own; no resource to authorize against. */
class StoreDeviceTokenRequest extends FormRequest
{
    public const TOKEN_PATTERN = '/^(Expo|Exponent)PushToken\[[A-Za-z0-9_-]+\]$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255', 'regex:'.self::TOKEN_PATTERN],
            'platform' => ['sometimes', 'nullable', Rule::in(['ios', 'android'])],
        ];
    }
}

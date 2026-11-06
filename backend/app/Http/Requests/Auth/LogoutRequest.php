<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // This phone's push token, so it stops getting pushes even if the app's own unregister call failed.
        return ['device_token' => ['nullable', 'string', 'max:255']];
    }
}

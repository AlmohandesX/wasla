<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Owners with a password confirm with it; Google-only owners confirm with a fresh Google ID token.
            'password' => [$this->user()?->hasPassword() === false ? 'prohibited' : 'required', 'string', 'max:128'],
            'google_id_token' => [$this->user()?->hasPassword() === false ? 'required' : 'prohibited', 'string', 'max:4096'],
        ];
    }
}

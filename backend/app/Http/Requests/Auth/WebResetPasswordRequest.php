<?php

namespace App\Http\Requests\Auth;

use App\Http\Controllers\ResetPasswordPageController;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The reset form on the web page. Same rules as the API plus a confirmation, because a person types
 * the password here with no show/hide control to check it.
 */
class WebResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ];
    }

    /**
     * Answer with the page itself, not a redirect: a redirect would flash the input, token included, into the session.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ResetPasswordPageController::page(
                (string) $this->input('email'),
                (string) $this->input('token'),
                422,
                errors: $validator->errors()->all(),
            ),
        );
    }
}

<?php

namespace App\Http\Requests\Cars;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for owner writes on a car or contact. Authorization runs before validation, so a stranger
 * gets 403 whatever they send. No rules: use as-is for body-less actions.
 */
class OwnerRequest extends FormRequest
{
    protected string $ability = 'update';

    public function authorize(): bool
    {
        $subject = $this->route('car') ?? $this->route('contact') ?? $this->route('thread') ?? $this->route('message') ?? $this->route('reminder');

        return $subject !== null && $this->user()->can($this->ability, $subject);
    }

    public function rules(): array
    {
        return [];
    }
}

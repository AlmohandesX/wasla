<?php

namespace App\Http\Requests\Visitor;

use App\Models\ChatMessage;
use App\Rules\MeaningfulText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendVisitorMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Anonymous by design; the controller checks the tag state and the session.
    }

    public function rules(): array
    {
        return [
            // Either a quick message or typed text, never both.
            'preset' => ['required_without:body', 'prohibits:body', 'string', Rule::in(ChatMessage::QUICK_PRESETS)],
            'body' => ['required_without:preset', 'string', 'max:500', new MeaningfulText],
            'consent' => ['sometimes', 'boolean'],
        ];
    }
}

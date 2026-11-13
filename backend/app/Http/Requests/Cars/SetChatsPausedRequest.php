<?php

namespace App\Http\Requests\Cars;

class SetChatsPausedRequest extends OwnerRequest
{
    public function rules(): array
    {
        return ['paused' => ['required', 'boolean']];
    }
}

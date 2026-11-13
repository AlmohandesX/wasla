<?php

namespace App\Http\Requests\Chat;

use App\Http\Requests\Cars\OwnerRequest;
use App\Rules\MeaningfulText;

class SendChatMessageRequest extends OwnerRequest
{
    protected string $ability = 'reply';

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:500', new MeaningfulText]];
    }
}

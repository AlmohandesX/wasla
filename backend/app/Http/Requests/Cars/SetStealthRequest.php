<?php

namespace App\Http\Requests\Cars;

class SetStealthRequest extends OwnerRequest
{
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }
}

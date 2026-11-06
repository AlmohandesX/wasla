<?php

namespace App\Http\Requests\Cars;

use App\Models\Reminder;
use Illuminate\Validation\Rule;

class UpdateReminderRequest extends OwnerRequest
{
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', 'string', Rule::in(Reminder::TYPES)],
            'due_on' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.now('Europe/Istanbul')->toDateString()],
        ];
    }
}

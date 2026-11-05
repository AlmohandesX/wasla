<?php

namespace App\Http\Requests\Cars;

use App\Models\Reminder;
use Illuminate\Validation\Rule;

class StoreReminderRequest extends OwnerRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(Reminder::TYPES)],
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Europe/Istanbul')->toDateString()],
        ];
    }
}

<?php

namespace App\Http\Requests\Visitor;

use Illuminate\Foundation\Http\FormRequest;

class ReportVisitorMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller only finds owner messages inside the caller's own thread.
    }

    public function rules(): array
    {
        return [];
    }
}

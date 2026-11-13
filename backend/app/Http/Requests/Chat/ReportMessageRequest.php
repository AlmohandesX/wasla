<?php

namespace App\Http\Requests\Chat;

use App\Http\Requests\Cars\OwnerRequest;

class ReportMessageRequest extends OwnerRequest
{
    protected string $ability = 'report';
}

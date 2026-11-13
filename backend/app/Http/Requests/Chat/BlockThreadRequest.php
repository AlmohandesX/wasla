<?php

namespace App\Http\Requests\Chat;

use App\Http\Requests\Cars\OwnerRequest;

class BlockThreadRequest extends OwnerRequest
{
    protected string $ability = 'block';
}

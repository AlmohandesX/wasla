<?php

namespace App\Http\Requests\Cars;

class DestroyCarRequest extends OwnerRequest
{
    protected string $ability = 'delete';
}

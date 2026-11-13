<?php

namespace App\Exceptions;

class CarAlreadyLinked extends TagException
{
    public string $errorCode = 'car_already_linked';

    public int $status = 409;
}

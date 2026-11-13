<?php

namespace App\Exceptions;

class CarNotOwned extends TagException
{
    public string $errorCode = 'car_not_owned';

    public int $status = 403;
}

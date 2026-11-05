<?php

namespace App\Services\Payments;

use RuntimeException;

/** No such provider here. The only gateway failure that may answer a public request with a 404. */
class UnknownPaymentGateway extends RuntimeException {}

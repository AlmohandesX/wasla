<?php

namespace App\Services\Payments;

use RuntimeException;

/** A real driver is missing its keys. Signing with an empty key would let anyone forge a callback, so it stops here. */
class PaymentGatewayNotConfigured extends RuntimeException {}

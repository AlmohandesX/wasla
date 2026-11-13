<?php

namespace App\Services\Payments;

use JsonSerializable;

/** Buyer details for one checkout. The national ID and phone are masked in every dump, export and log context. */
final class BillingDetails implements JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
        public readonly string $city,
        #[\SensitiveParameter] public readonly string $nationalId,
        #[\SensitiveParameter] public readonly string $phone,
    ) {}

    public function __debugInfo(): array
    {
        return $this->masked();
    }

    public function jsonSerialize(): array
    {
        return $this->masked();
    }

    /** Keeps the national ID and phone out of queued payloads, caches and session data. */
    public function __serialize(): array
    {
        throw new \LogicException('BillingDetails must not be serialized.');
    }

    private function masked(): array
    {
        return ['name' => $this->name, 'address' => $this->address, 'city' => $this->city, 'nationalId' => '***', 'phone' => '***'];
    }
}

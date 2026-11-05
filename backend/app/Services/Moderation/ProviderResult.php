<?php

namespace App\Services\Moderation;

final readonly class ProviderResult
{
    /** @param array<string, float> $scores category => score */
    public function __construct(public array $scores) {}
}

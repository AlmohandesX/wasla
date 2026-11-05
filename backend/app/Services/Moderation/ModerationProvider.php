<?php

namespace App\Services\Moderation;

interface ModerationProvider
{
    /** @throws ProviderUnavailable when the service fails, times out or is not configured */
    public function check(string $text): ProviderResult;
}

<?php

namespace App\Services\Moderation;

use App\Enums\ModerationDecision;

final readonly class ModerationResult
{
    /** @param 'blacklist'|'provider'|'fallback' $source */
    public function __construct(
        public ModerationDecision $decision,
        public string $source,
        public ?string $reason = null,
    ) {}

    public function blocked(): bool
    {
        return $this->decision === ModerationDecision::Block;
    }
}

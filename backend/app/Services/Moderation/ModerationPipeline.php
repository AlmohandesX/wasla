<?php

namespace App\Services\Moderation;

use App\Enums\ModerationDecision;
use App\Models\BlacklistTerm;
use App\Models\ModerationEvent;
use Illuminate\Support\Facades\Cache;

class ModerationPipeline
{
    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly ModerationProvider $provider,
    ) {}

    /**
     * @param  array{surface?: string, thread_id?: int|null, user_id?: int|null}  $context
     */
    public function check(string $text, array $context = [], bool $useProvider = true): ModerationResult
    {
        $hit = $this->blacklistHit($this->normalizer->normalize($text));
        if ($hit !== null) {
            return $this->finish($text, $context, new ModerationResult(ModerationDecision::Block, 'blacklist', 'blacklist: '.$hit));
        }

        if (! $useProvider) {
            return new ModerationResult(ModerationDecision::Allow, 'blacklist');
        }

        if (! $this->reserveCall()) {
            return $this->finish($text, $context, new ModerationResult(ModerationDecision::AllowFlagged, 'fallback', 'daily cap reached'));
        }

        try {
            $scores = $this->provider->check($text)->scores;
        } catch (ProviderUnavailable $e) {
            return $this->finish($text, $context, new ModerationResult(ModerationDecision::AllowFlagged, 'fallback', $e->getMessage()));
        }

        foreach ($scores as $category => $score) {
            $threshold = (float) config("wasla.moderation.thresholds.$category", config('wasla.moderation.threshold'));
            if ($score >= $threshold) {
                return $this->finish($text, $context, new ModerationResult(
                    ModerationDecision::Block, 'provider', sprintf('%s (%.2f)', $category, $score),
                ));
            }
        }

        return new ModerationResult(ModerationDecision::Allow, 'provider');
    }

    private function blacklistHit(string $normalizedText): ?string
    {
        foreach (BlacklistTerm::query()->where('active', true)->get(['term', 'normalized', 'match_mode']) as $term) {
            if ($term->normalized === '') {
                continue;
            }

            $matches = $term->match_mode === 'substring'
                ? str_contains($normalizedText, $term->normalized)
                : preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term->normalized, '/').'(?![\p{L}\p{N}])/u', $normalizedText) === 1;

            if ($matches) {
                return $term->term;
            }
        }

        return null;
    }

    /** Counts a provider call against today's (Istanbul) cap; false when the cap is spent. */
    private function reserveCall(): bool
    {
        $key = 'moderation:calls:'.now(config('wasla.display_timezone'))->toDateString();
        Cache::add($key, 0, now()->addDays(2));

        return Cache::increment($key) <= (int) config('wasla.moderation.daily_cap');
    }

    private function finish(string $text, array $context, ModerationResult $result): ModerationResult
    {
        ModerationEvent::create([
            'kind' => $result->blocked() ? 'blocked' : 'flagged',
            'surface' => $context['surface'] ?? 'chat',
            'text' => $text,
            'source' => $result->source,
            'reason' => $result->reason,
            'thread_id' => $context['thread_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
        ]);

        return $result;
    }
}

<?php

namespace App\Services\Moderation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiModerationProvider implements ModerationProvider
{
    public function check(string $text): ProviderResult
    {
        $key = (string) config('wasla.moderation.api_key');
        if ($key === '') {
            throw new ProviderUnavailable('OpenAI API key is not configured.');
        }

        try {
            // Only the text and the model name leave the server. No retries in the request path.
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout((int) config('wasla.moderation.timeout'))
                ->post(config('wasla.moderation.endpoint'), [
                    'model' => config('wasla.moderation.model'),
                    'input' => $text,
                ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable('OpenAI request failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new ProviderUnavailable('OpenAI returned HTTP '.$response->status());
        }

        $scores = $response->json('results.0.category_scores');
        if (! is_array($scores)) {
            throw new ProviderUnavailable('OpenAI response had no category scores.');
        }

        return new ProviderResult(array_map('floatval', $scores));
    }
}

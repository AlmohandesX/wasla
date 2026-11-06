<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\ExpoPushClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, scalar> $data */
    public function __construct(
        public readonly int $userId,
        public readonly string $titleKey,
        public readonly string $bodyKey,
        public readonly array $data = [],
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ExpoPushClient $expo): void
    {
        $user = User::find($this->userId);
        $tokens = $user?->deviceTokens()->pluck('token')->all() ?? [];
        if ($tokens === []) {
            return;
        }

        $locale = $user->preferredLocale();
        $title = __($this->titleKey, $this->data, $locale);
        $body = __($this->bodyKey, $this->data, $locale);

        $dead = $expo->send(array_map(fn (string $token) => [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'data' => $this->data,
        ], $tokens));

        if ($dead !== []) {
            DeviceToken::query()->whereIn('token', $dead)->delete();
        }
    }
}

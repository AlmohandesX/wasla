<?php

namespace App\Services\Chat;

use App\Enums\ModerationDecision;
use App\Exceptions\MessageBlocked;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\Moderation\ModerationPipeline;
use Illuminate\Support\Facades\Cache;

class ChatService
{
    public const BLOCK_COOLDOWN_HOURS = 24;

    public function __construct(private readonly ModerationPipeline $pipeline) {}

    /** @param 'visitor'|'owner' $sender */
    public function send(ChatThread $thread, string $sender, string $body, bool $useProvider = true): ChatMessage
    {
        $result = $this->pipeline->check($body, [
            'surface' => 'chat',
            'thread_id' => $thread->id,
            'user_id' => $sender === 'owner' ? $thread->car->user_id : null,
        ], $useProvider);

        if ($result->blocked()) {
            throw new MessageBlocked;
        }

        $message = $thread->messages()->create([
            'sender' => $sender,
            'body' => $body,
            'moderation_status' => $result->decision === ModerationDecision::AllowFlagged ? 'flagged' : 'passed',
        ]);
        $thread->touch();

        return $message;
    }

    /** Blocks the visitor session and starts a 24-hour cooldown for its IP on this car only. */
    public function block(ChatThread $thread): void
    {
        $thread->update(['blocked_at' => now()]);
        Cache::put(
            self::cooldownKey($thread->car_id, $thread->visitorSession->ip_hash),
            true,
            now()->addHours(self::BLOCK_COOLDOWN_HOURS),
        );
    }

    /** The one place the cooldown cache key is built; the visitor side reads the same key. */
    public static function cooldownKey(int $carId, string $ipHash): string
    {
        return "chat:block-cooldown:$carId:$ipHash";
    }

    public static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}

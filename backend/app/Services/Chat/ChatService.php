<?php

namespace App\Services\Chat;

use App\Enums\ModerationDecision;
use App\Events\ChatMessageCreated;
use App\Exceptions\MessageBlocked;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\Moderation\ModerationPipeline;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

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

        return $this->store($thread, [
            'sender' => $sender,
            'body' => $body,
            'moderation_status' => $result->decision === ModerationDecision::AllowFlagged ? 'flagged' : 'passed',
        ]);
    }

    /**
     * A visitor's one-tap message. The text is ours, so it skips moderation (and the outside provider, which is why
     * it needs no consent). The Turkish text is stored as the body for anything that reads the column directly;
     * readers get it in their own language through ChatMessage::displayBody().
     */
    public function sendQuick(ChatThread $thread, string $preset): ChatMessage
    {
        if (! in_array($preset, ChatMessage::QUICK_PRESETS, true)) {
            throw new InvalidArgumentException("Unknown quick message [$preset].");
        }

        return $this->store($thread, [
            'sender' => 'visitor',
            'body' => __('wasla.quick_messages.'.$preset, [], 'tr'),
            'preset_key' => $preset,
            'moderation_status' => 'passed',
        ]);
    }

    private function store(ChatThread $thread, array $attributes): ChatMessage
    {
        $message = $thread->messages()->create($attributes);
        $thread->touch();
        ChatMessageCreated::dispatch($message);

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

<?php

namespace App\Listeners;

use App\Events\ChatMessageCreated;
use App\Models\ChatMessage;
use App\Services\Push\PushNotifier;

/**
 * Pushes the car owner on a visitor message, with the text so the owner can act from the lock screen:
 *
 * - a quick message in full, in the owner's language (the text is ours);
 * - typed text, up to PREVIEW_LENGTH characters, only when moderation passed it. Text the provider flagged, and
 *   anything else, gets the generic line, so unreviewed words never reach the push service or the lock screen.
 */
class NotifyOwnerOfVisitorMessage
{
    public const PREVIEW_LENGTH = 100;

    public function __construct(private readonly PushNotifier $push) {}

    public function handle(ChatMessageCreated $event): void
    {
        $message = $event->message;
        if ($message->sender !== 'visitor') {
            return;
        }

        // An erase can race this event: nothing to notify then.
        $car = $message->thread?->car;
        if ($car?->user === null) {
            return;
        }

        $data = ['thread_id' => $message->thread_id, 'car_id' => $car->id];
        $replace = ['plate' => $car->plate];
        $title = $car->plate !== '' ? 'wasla.push_chat_title_car' : 'wasla.push_chat_title';

        if ($message->isQuickMessage()) {
            // Translated when the job runs, in the owner's locale.
            $this->push->notify($car->user, $title, 'wasla.quick_messages.'.$message->preset_key, $data, $replace);
        } elseif ($message->moderation_status === 'passed' && ! $message->isHidden()) {
            $this->push->notify($car->user, $title, 'wasla.push_chat_text', $data, $replace + ['text' => self::preview($message)]);
        } else {
            $this->push->notify($car->user, $title, 'wasla.push_chat_body', $data, $replace);
        }
    }

    /** The message on one line, cut to PREVIEW_LENGTH characters including the ellipsis. */
    public static function preview(ChatMessage $message): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $message->body));

        return mb_strlen($text) > self::PREVIEW_LENGTH
            ? rtrim(mb_substr($text, 0, self::PREVIEW_LENGTH - 1)).'…'
            : $text;
    }
}

<?php

namespace App\Listeners;

use App\Events\ChatMessageCreated;
use App\Services\Push\PushNotifier;

/** Pushes the car owner on a visitor message. The text itself never goes to the push service. */
class NotifyOwnerOfVisitorMessage
{
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

        $this->push->notify($car->user, 'wasla.push_chat_title', 'wasla.push_chat_body', [
            'thread_id' => $message->thread_id,
            'car_id' => $car->id,
        ]);
    }
}

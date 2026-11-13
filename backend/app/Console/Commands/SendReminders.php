<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Models\SentNotification;
use App\Services\Push\PushNotifier;
use App\Services\Tags\SubscriptionService;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    public const DAYS_BEFORE = [7, 1, 0];

    protected $signature = 'reminders:send';

    protected $description = 'Push car reminders due in 7 days, 1 day or today (Europe/Istanbul calendar)';

    public function handle(PushNotifier $push, SubscriptionService $subscriptions): int
    {
        $today = now('Europe/Istanbul')->startOfDay();
        $byDate = collect(self::DAYS_BEFORE)->mapWithKeys(fn (int $days) => [$today->copy()->addDays($days)->toDateString() => $days]);

        Reminder::query()
            ->whereIn('due_on', $byDate->keys()->all())
            // A suspended owner cannot sign in, so they get no pushes; nothing is claimed, so reinstating resumes them.
            ->whereHas('car.user', fn ($users) => $users->whereNull('suspended_at'))
            ->with('car.user', 'car.tag')
            ->each(function (Reminder $reminder) use ($byDate, $push, $subscriptions) {
                $tag = $reminder->car->tag;
                if ($tag !== null && ! $subscriptions->isActive($tag)) {
                    return; // expired cars are hard-stopped, reminders included
                }

                $due = $reminder->due_on->toDateString();
                $days = $byDate[$due];
                if (! SentNotification::claim('reminder', $reminder->id, "d$days:$due")) {
                    return;
                }

                $push->notify($reminder->car->user, 'wasla.push_reminder_'.$reminder->type, "wasla.push_reminder_body_d$days", [
                    'car_id' => $reminder->car_id,
                    'type' => $reminder->type,
                    'days' => $days,
                ]);
            });

        return self::SUCCESS;
    }
}

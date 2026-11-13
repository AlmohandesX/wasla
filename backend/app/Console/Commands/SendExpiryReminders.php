<?php

namespace App\Console\Commands;

use App\Models\SentNotification;
use App\Models\Tag;
use App\Services\Push\PushNotifier;
use Illuminate\Console\Command;

class SendExpiryReminders extends Command
{
    public const DAYS_BEFORE = [30, 7, 1];

    protected $signature = 'reminders:expiry';

    protected $description = 'Push subscription expiry notices 30, 7 and 1 days ahead (Europe/Istanbul calendar)';

    public function handle(PushNotifier $push): int
    {
        $today = now('Europe/Istanbul')->startOfDay();

        foreach (self::DAYS_BEFORE as $days) {
            // The Istanbul calendar day that is $days from today, as a UTC range.
            $day = $today->copy()->addDays($days);

            Tag::query()
                ->whereNotNull('car_id')
                ->whereNull('revoked_at')
                ->where('expires_at', '>=', $day->copy()->utc())
                ->where('expires_at', '<', $day->copy()->addDay()->utc())
                // A suspended owner cannot sign in or renew, so they get no pushes.
                ->whereHas('car.user', fn ($users) => $users->whereNull('suspended_at'))
                ->with('car.user')
                ->each(function (Tag $tag) use ($days, $push) {
                    // The expiry date is in the marker, so a renewed tag gets its notices again next year.
                    if (! SentNotification::claim('expiry', $tag->id, "d$days:".$tag->expires_at->toDateString())) {
                        return;
                    }

                    $push->notify($tag->car->user, 'wasla.push_expiry_title', "wasla.push_expiry_body_d$days", [
                        'car_id' => $tag->car_id,
                        'days' => $days,
                    ]);
                });
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Filament\Support;

use App\Exceptions\AdminActionException;
use Filament\Notifications\Notification;

class AdminActionRunner
{
    /**
     * Runs a service call and reports success or the refusal. Unrelated errors still surface.
     * A call that returns an array gives the success message its placeholders (for example a count).
     */
    public static function run(callable $call, string $successKey): void
    {
        try {
            $result = $call();
        } catch (AdminActionException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__($successKey, is_array($result) ? $result : []))->success()->send();
    }
}

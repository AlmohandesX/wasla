<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily jobs run on the Istanbul clock.
Schedule::command('reminders:send')->dailyAt('09:00')->timezone('Europe/Istanbul')->withoutOverlapping();
Schedule::command('reminders:expiry')->dailyAt('09:00')->timezone('Europe/Istanbul')->withoutOverlapping();
Schedule::command('chats:purge')->dailyAt('03:00')->timezone('Europe/Istanbul')->withoutOverlapping();

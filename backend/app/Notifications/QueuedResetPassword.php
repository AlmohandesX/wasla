<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queued so response time and status do not reveal whether the email exists. */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}

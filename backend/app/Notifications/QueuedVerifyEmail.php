<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Sent from a queue so an SMTP failure cannot fail the request that triggered it. */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /** An erased user must not leave a failed job holding a token. */
    public bool $deleteWhenMissingModels = true;
}

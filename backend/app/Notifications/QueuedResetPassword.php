<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued so response time and status do not reveal whether the email exists. Encrypted so the plaintext token never
 * sits readable in the jobs or failed_jobs table.
 */
class QueuedResetPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** An erased user must not leave a failed job holding a token. */
    public bool $deleteWhenMissingModels = true;
}

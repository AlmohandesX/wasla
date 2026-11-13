<?php

namespace App\Services\Push;

use App\Jobs\SendPushJob;
use App\Models\User;

class PushNotifier
{
    /**
     * Queues a push to every device of $user. The text is translated in the user's locale when the
     * job runs; $data fills :placeholders and travels in the payload, so keep private text (chat
     * bodies, plates, names) out of it. $replace fills :placeholders only and is shown in the
     * notification, so it may carry text the owner is meant to read there.
     *
     * @param  array<string, scalar>  $data
     * @param  array<string, scalar>  $replace
     */
    public function notify(User $user, string $titleKey, string $bodyKey, array $data = [], array $replace = []): void
    {
        SendPushJob::dispatch($user->id, $titleKey, $bodyKey, $data, $replace);
    }
}

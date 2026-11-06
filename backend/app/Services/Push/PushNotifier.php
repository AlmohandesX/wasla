<?php

namespace App\Services\Push;

use App\Jobs\SendPushJob;
use App\Models\User;

class PushNotifier
{
    /**
     * Queues a push to every device of $user. The text is translated in the user's locale when the
     * job runs; $data fills :placeholders and travels in the payload, so keep private text (chat
     * bodies, plates, names) out of it.
     *
     * @param  array<string, scalar>  $data
     */
    public function notify(User $user, string $titleKey, string $bodyKey, array $data = []): void
    {
        SendPushJob::dispatch($user->id, $titleKey, $bodyKey, $data);
    }
}

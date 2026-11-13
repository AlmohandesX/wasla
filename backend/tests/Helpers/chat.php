<?php

use App\Models\Car;
use App\Models\ChatThread;
use App\Models\VisitorSession;
use App\Services\Chat\ChatService;

function visitorSession(string $ip = '10.1.1.1'): VisitorSession
{
    return VisitorSession::create([
        'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
        'ip_hash' => ChatService::hashIp($ip),
        'last_seen_at' => now(),
    ]);
}

/** A thread on $car from a fresh visitor, with one visitor message already in it. */
function chatThread(Car $car, string $firstMessage = 'Aracinizin isigi acik kalmis'): ChatThread
{
    $thread = ChatThread::create(['car_id' => $car->id, 'visitor_session_id' => visitorSession()->id]);
    $thread->messages()->create(['sender' => 'visitor', 'body' => $firstMessage]);

    return $thread;
}

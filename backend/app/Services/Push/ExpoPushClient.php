<?php

namespace App\Services\Push;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** Thin client for the Expo push service. Messages carry only generic titles and bodies. */
class ExpoPushClient
{
    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public const BATCH_SIZE = 100;

    /**
     * Sends messages in batches of 100.
     *
     * @param  list<array<string, mixed>>  $messages  each with a `to` token
     * @return list<string> tokens Expo says are no longer registered
     *
     * @throws RequestException when Expo answers with an error status
     */
    public function send(array $messages): array
    {
        $dead = [];

        foreach (array_chunk($messages, self::BATCH_SIZE) as $batch) {
            $request = Http::acceptJson()->asJson()->timeout(10);
            if ($token = config('services.expo.access_token')) {
                $request = $request->withToken($token);
            }

            $tickets = $request->post(self::ENDPOINT, $batch)->throw()->json('data', []);

            // Tickets come back in the same order as the batch.
            foreach ($tickets as $i => $ticket) {
                if (($ticket['status'] ?? null) === 'error' && ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $dead[] = $batch[$i]['to'];
                }
            }
        }

        return $dead;
    }
}

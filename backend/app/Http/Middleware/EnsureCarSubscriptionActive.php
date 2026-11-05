<?php

namespace App\Http\Middleware;

use App\Exceptions\SubscriptionExpired;
use App\Models\Car;
use App\Models\EmergencyContact;
use App\Services\Tags\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard stop for a car whose tag subscription expired. A car with no tag is not blocked. Only the
 * car's owner sees 402: for anyone else the request passes on and the policy answers 403, so expiry
 * is never revealed. Works for {car} and {contact} routes, whether or not bindings ran yet.
 */
class EnsureCarSubscriptionActive
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $car = $this->car($request);
        $tag = $car?->user_id === $request->user()?->id ? $car?->tag : null;

        if ($tag !== null && ! $this->subscriptions->isActive($tag)) {
            throw new SubscriptionExpired;
        }

        return $next($request);
    }

    private function car(Request $request): ?Car
    {
        $car = $request->route('car');
        if ($car !== null) {
            return $car instanceof Car ? $car : Car::find($car);
        }

        $contact = $request->route('contact');
        if ($contact !== null) {
            $contact = $contact instanceof EmergencyContact ? $contact : EmergencyContact::find($contact);

            return $contact?->car;
        }

        return null;
    }
}

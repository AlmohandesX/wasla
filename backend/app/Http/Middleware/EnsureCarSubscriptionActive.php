<?php

namespace App\Http\Middleware;

use App\Exceptions\SubscriptionExpired;
use App\Models\Car;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\EmergencyContact;
use App\Models\Reminder;
use App\Services\Tags\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard stop for a car whose tag subscription expired. A car with no tag is not blocked. Only the
 * car's owner sees 402: for anyone else the request passes on and the policy answers 403, so expiry
 * is never revealed. Works for {car}, {contact}, {thread}, {message} and {reminder} routes, whether or not
 * bindings ran yet.
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
        return $this->resolve($request, 'car', Car::class)
            ?? $this->resolve($request, 'contact', EmergencyContact::class)?->car
            ?? $this->resolve($request, 'thread', ChatThread::class)?->car
            ?? $this->resolve($request, 'message', ChatMessage::class)?->thread->car
            ?? $this->resolve($request, 'reminder', Reminder::class)?->car;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function resolve(Request $request, string $parameter, string $class): ?object
    {
        $value = $request->route($parameter);

        return $value === null || $value instanceof $class ? $value : $class::find($value);
    }
}

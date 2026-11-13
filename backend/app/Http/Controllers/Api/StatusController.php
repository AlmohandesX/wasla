<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\OwnerRequest;
use App\Http\Requests\Cars\SetChatsPausedRequest;
use App\Http\Requests\Cars\SetStatusRequest;
use App\Http\Requests\Cars\SetStealthRequest;
use App\Http\Resources\CarResource;
use App\Models\Car;
use App\Services\Moderation\OwnerTextModerator;

/** Per-car switches: stealth, paused chats and the dynamic status. */
class StatusController extends Controller
{
    public function __construct(private readonly OwnerTextModerator $moderator) {}

    public function stealth(SetStealthRequest $request, Car $car): CarResource
    {
        $car->update(['stealth_enabled' => $request->boolean('enabled')]);

        return $this->respond($car);
    }

    public function chatsPaused(SetChatsPausedRequest $request, Car $car): CarResource
    {
        $car->update(['chats_paused' => $request->boolean('paused')]);

        return $this->respond($car);
    }

    public function set(SetStatusRequest $request, Car $car): CarResource
    {
        $preset = $request->validated('preset');
        if ($preset !== null) {
            // Our own wording, so there is nothing to moderate. The Turkish text is kept as a plain fallback.
            $key = $preset;
            $text = __('wasla.status_presets.'.$preset, [], 'tr');
        } else {
            $key = null;
            $text = $request->string('text')->toString();
            $this->moderator->assertAllowed($request->user(), $text, 'status');
        }

        $car->update([
            'status_text' => $text,
            'status_key' => $key,
            'status_expires_at' => now()->addMinutes($request->integer('duration_minutes')),
        ]);

        return $this->respond($car);
    }

    public function clear(OwnerRequest $request, Car $car): CarResource
    {
        $car->update(['status_text' => null, 'status_key' => null, 'status_expires_at' => null]);

        return $this->respond($car);
    }

    private function respond(Car $car): CarResource
    {
        return new CarResource($car->load('tag'));
    }
}

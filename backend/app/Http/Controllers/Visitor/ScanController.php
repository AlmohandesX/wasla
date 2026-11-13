<?php

namespace App\Http\Controllers\Visitor;

use App\Enums\PublicTagState;
use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\Tag;
use App\Services\Tags\PublicTagStateResolver;
use App\Services\Tags\TokenGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class ScanController extends Controller
{
    public function __construct(private PublicTagStateResolver $states) {}

    public function show(string $token): View
    {
        $tag = $this->find($token);

        return match ($this->states->resolve($tag)) {
            PublicTagState::Unclaimed => view('visitor.unclaimed'),
            // No tag data may reach this view: every neutral reason must render the same bytes.
            PublicTagState::Neutral => view('visitor.neutral'),
            PublicTagState::Active => view('visitor.active', [
                'token' => $tag->token,
                'message' => $tag->car->currentMessage(),
                'until' => $this->until($tag->car),
                'contacts' => $tag->car->contacts,
            ]),
        };
    }

    /**
     * When the owner's status runs out, for the line under the note: the Istanbul clock time (with the date when
     * it is not today) and the time left, rounded up so it never reads "0 min". Null without an active status.
     *
     * @return array{iso: string, text: string}|null
     */
    private function until(Car $car): ?array
    {
        if (! $car->hasActiveStatus()) {
            return null;
        }

        $now = now(config('wasla.display_timezone'));
        $end = $car->status_expires_at->copy()->setTimezone(config('wasla.display_timezone'))->locale(app()->getLocale());
        $time = $end->isSameDay($now) ? $end->format('H:i') : $end->translatedFormat('j M, H:i');

        $minutes = (int) ceil($now->diffInSeconds($end) / 60);
        $left = match (true) {
            $minutes < 60 => __('visitor.left_minutes', ['m' => $minutes]),
            $minutes < 1440 => $minutes % 60 === 0
                ? __('visitor.left_hours', ['h' => intdiv($minutes, 60)])
                : __('visitor.left_hours_minutes', ['h' => intdiv($minutes, 60), 'm' => $minutes % 60]),
            default => __('visitor.left_days', ['d' => (int) ceil($minutes / 1440)]),
        };

        return ['iso' => $end->toIso8601String(), 'text' => __('visitor.status_until', ['time' => $time, 'left' => $left])];
    }

    public function reveal(string $token, string $contact): JsonResponse
    {
        $tag = $this->find($token);
        abort_unless($this->states->resolve($tag) === PublicTagState::Active, 404);

        $found = ctype_digit($contact) ? $tag->car->contacts->firstWhere('id', (int) $contact) : null;
        abort_if($found === null, 404);

        return response()->json(['phone' => $found->phone]);
    }

    /** Checked before any query: non-ASCII input against the ascii_bin column raises a collation error. */
    private function find(string $token): Tag
    {
        abort_unless(TokenGenerator::isWellFormed($token), 404);

        return Tag::with('car.user')->where('token', $token)->firstOr(fn () => abort(404));
    }
}

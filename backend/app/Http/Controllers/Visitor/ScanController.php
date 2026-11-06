<?php

namespace App\Http\Controllers\Visitor;

use App\Enums\PublicTagState;
use App\Http\Controllers\Controller;
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
                'contacts' => $tag->car->contacts,
            ]),
        };
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

<?php

namespace App\Http\Controllers\Visitor;

use App\Enums\PublicTagState;
use App\Exceptions\ChatRateLimited;
use App\Exceptions\ChatsPaused;
use App\Exceptions\ChatUnavailable;
use App\Exceptions\MessageBlocked;
use App\Http\Controllers\Controller;
use App\Http\Requests\Visitor\ReportVisitorMessageRequest;
use App\Http\Requests\Visitor\SendVisitorMessageRequest;
use App\Http\Resources\VisitorMessageResource;
use App\Models\Car;
use App\Models\ChatThread;
use App\Models\Tag;
use App\Models\VisitorSession;
use App\Services\Chat\ChatService;
use App\Services\Chat\VisitorSessionResolver;
use App\Services\Tags\PublicTagStateResolver;
use App\Services\Tags\TokenGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Anonymous chat behind the scan page. Whatever the reason a visitor cannot chat (stealth, expiry, suspension,
 * revoked or unclaimed tag, a block on the session, a cooldown on the IP) the answer is the same ChatUnavailable,
 * so a harasser learns nothing about which switch stopped them.
 */
class VisitorChatController extends Controller
{
    private const PAGE = 100;

    public function __construct(
        private readonly ChatService $chat,
        private readonly PublicTagStateResolver $states,
        private readonly VisitorSessionResolver $sessions,
    ) {}

    public function show(Request $request, string $token): View
    {
        try {
            [$tag, , $thread] = $this->context($request, $token);
        } catch (ChatUnavailable) {
            return view('visitor.neutral');
        }

        $paused = false;
        if ($thread === null) {
            try {
                $this->assertCanStartThread($request, $tag->car);
            } catch (ChatUnavailable) {
                return view('visitor.neutral');
            } catch (ChatsPaused) {
                $paused = true;
            }
        }

        return view('visitor.chat', [
            'token' => $tag->token,
            'paused' => $paused,
            'consentRequired' => (bool) config('wasla.chat.consent_required'),
        ]);
    }

    /** Polling: pass the highest message id seen as ?after=. */
    public function messages(Request $request, string $token): JsonResponse
    {
        [, , $thread] = $this->context($request, $token);
        if ($thread === null) {
            return response()->json(['data' => []]);
        }

        $messages = $thread->messages()
            ->where('id', '>', max(0, (int) $request->query('after', 0)))
            ->orderBy('id')->limit(self::PAGE)->get();

        return response()->json(['data' => VisitorMessageResource::collection($messages)->resolve($request)]);
    }

    public function store(SendVisitorMessageRequest $request, string $token): JsonResponse
    {
        [$tag, $session, $thread] = $this->context($request, $token);

        $isNewSession = $session === null;
        if (! $isNewSession) {
            $this->hitMessageBudget($session);
        }
        if ($thread === null) {
            $this->assertCanStartThread($request, $tag->car);
            // Counted before moderation runs, so refused attempts use up the budget too.
            $this->hit('chat:threads:'.ChatService::hashIp((string) $request->ip()), (int) config('wasla.chat.new_threads_per_hour'), 3600);
            $session ??= $this->sessions->resolve($request);
            if ($isNewSession) {
                $this->hitMessageBudget($session);
            }
            $thread = ChatThread::firstOrCreate(['car_id' => $tag->car_id, 'visitor_session_id' => $session->id]);
        }

        $consented = ! config('wasla.chat.consent_required') || $request->boolean('consent');

        try {
            $message = $this->chat->send($thread, 'visitor', $request->validated('body'), $consented);
        } catch (MessageBlocked $e) {
            // A thread that only ever held a refused message must not show up in the owner's list.
            if ($thread->wasRecentlyCreated) {
                $thread->delete();
            }
            throw $e;
        }

        return (new VisitorMessageResource($message))->response()->setStatusCode(201);
    }

    public function report(ReportVisitorMessageRequest $request, string $token, string $message): JsonResponse
    {
        [, , $thread] = $this->context($request, $token);
        abort_if($thread === null, 404);

        $found = $thread->messages()->where('sender', 'owner')->find((int) $message);
        abort_if($found === null, 404);
        $found->update(['reported_at' => $found->reported_at ?? now()]);

        return response()->json(['reported' => true]);
    }

    /**
     * @return array{0: Tag, 1: ?VisitorSession, 2: ?ChatThread}
     *
     * @throws ChatUnavailable when the tag is not live or the caller's thread is blocked
     */
    private function context(Request $request, string $token): array
    {
        $tag = $this->find($token);
        if ($this->states->resolve($tag) !== PublicTagState::Active) {
            throw new ChatUnavailable;
        }

        $session = $this->sessions->find($request);
        $thread = $session === null ? null : ChatThread::query()
            ->where('car_id', $tag->car_id)->where('visitor_session_id', $session->id)->first();
        if ($thread?->isBlocked()) {
            throw new ChatUnavailable;
        }

        return [$tag, $session, $thread];
    }

    /** A cooldown looks exactly like any other unavailability; a pause is the one reason a visitor is told. */
    private function assertCanStartThread(Request $request, Car $car): void
    {
        if (Cache::has(ChatService::cooldownKey($car->id, ChatService::hashIp((string) $request->ip())))) {
            throw new ChatUnavailable;
        }
        if ($car->chats_paused) {
            throw new ChatsPaused;
        }
    }

    private function hitMessageBudget(VisitorSession $session): void
    {
        $this->hit('chat:messages:'.$session->id, (int) config('wasla.chat.messages_per_window'), (int) config('wasla.chat.window_minutes') * 60);
    }

    private function hit(string $key, int $max, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ChatRateLimited;
        }
        RateLimiter::hit($key, $decaySeconds);
    }

    /** Checked before any query: non-ASCII input against the ascii_bin column raises a collation error. */
    private function find(string $token): Tag
    {
        abort_unless(TokenGenerator::isWellFormed($token), 404);

        return Tag::with('car.user')->where('token', $token)->firstOr(fn () => abort(404));
    }
}

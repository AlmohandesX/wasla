<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ChatUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\BlockThreadRequest;
use App\Http\Requests\Chat\ReportMessageRequest;
use App\Http\Requests\Chat\SendChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ChatThreadResource;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ChatController extends Controller
{
    private const PAGE = 100;

    public function __construct(private readonly ChatService $chat) {}

    public function index(Request $request): JsonResponse
    {
        $threads = ChatThread::query()
            // Hard stop: threads of a car whose tag expired are left out; cars without a tag still list.
            ->whereHas('car', fn ($q) => $q->where('user_id', $request->user()->id)
                ->where(fn ($q) => $q->whereDoesntHave('tag')
                    ->orWhereHas('tag', fn ($t) => $t->where('expires_at', '>', now()))))
            ->with('lastMessage')
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender', 'visitor')->whereNull('read_at')])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::PAGE)
            ->get();

        return response()->json(['data' => ChatThreadResource::collection($threads)->resolve($request)]);
    }

    /** Polling: pass the highest message id seen as ?after=. Returned visitor messages count as read. */
    public function messages(Request $request, ChatThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);
        $after = max(0, (int) $request->query('after', 0));

        $ids = $thread->messages()->where('id', '>', $after)->orderBy('id')->limit(self::PAGE)->pluck('id');
        $thread->messages()->whereIn('id', $ids)->where('sender', 'visitor')->whereNull('read_at')->update(['read_at' => now()]);
        $messages = $thread->messages()->whereIn('id', $ids)->orderBy('id')->get();

        return response()->json(['data' => ChatMessageResource::collection($messages)->resolve($request)]);
    }

    public function send(SendChatMessageRequest $request, ChatThread $thread): JsonResponse
    {
        // The subscription middleware already answered 402 for an expired tag; here a missing tag or a block on the thread is left.
        if ($thread->car->tag === null || $thread->isBlocked()) {
            throw new ChatUnavailable;
        }

        $message = $this->chat->send($thread, 'owner', $request->validated('body'));

        return (new ChatMessageResource($message))->response()->setStatusCode(201);
    }

    public function block(BlockThreadRequest $request, ChatThread $thread): ChatThreadResource
    {
        $this->chat->block($thread);

        return new ChatThreadResource($thread->refresh());
    }

    public function report(ReportMessageRequest $request, ChatMessage $message): ChatMessageResource
    {
        $message->update(['reported_at' => $message->reported_at ?? now()]);

        return new ChatMessageResource($message);
    }
}

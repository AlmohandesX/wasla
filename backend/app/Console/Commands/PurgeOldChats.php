<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ModerationEvent;
use App\Models\VisitorSession;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PurgeOldChats extends Command
{
    public const RETENTION_DAYS = 90;

    private const CHUNK = 1000;

    protected $signature = 'chats:purge';

    protected $description = 'Delete chat messages, idle threads, moderation events and idle visitor sessions older than 90 days';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        // Order matters: visitor sessions cascade to their threads, so a session goes only once it has none.
        $messages = $this->purge(ChatMessage::query()->where('created_at', '<', $cutoff));
        $threads = $this->purge(ChatThread::query()->where('updated_at', '<', $cutoff)->whereDoesntHave('messages'));
        $events = $this->purge(ModerationEvent::query()->where('created_at', '<', $cutoff));
        $sessions = $this->purge(VisitorSession::query()
            ->whereDoesntHave('threads')
            ->where(fn (Builder $q) => $q->where('last_seen_at', '<', $cutoff)
                ->orWhere(fn (Builder $q) => $q->whereNull('last_seen_at')->where('created_at', '<', $cutoff))));

        $this->info("Purged $messages messages, $threads threads, $events moderation events, $sessions visitor sessions.");

        return self::SUCCESS;
    }

    /** Deletes in chunks so a large backlog never holds one long lock. */
    private function purge(Builder $query): int
    {
        $total = 0;
        while (($ids = (clone $query)->limit(self::CHUNK)->pluck('id'))->isNotEmpty()) {
            $total += $query->getModel()->newQuery()->whereKey($ids)->delete();
        }

        return $total;
    }
}

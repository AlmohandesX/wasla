<?php

namespace App\Services\Chat;

use App\Exceptions\AdminActionException;
use App\Models\ChatMessage;
use App\Models\MessageReport;
use App\Models\User;
use App\Models\VisitorSession;
use App\Services\Auth\UserAdminService;
use App\Services\Concerns\AuditsAdminActions;
use Illuminate\Support\Facades\DB;

/**
 * Reports from either side of a chat, and what an admin does about them. Each admin action closes the report
 * with that action as its resolution; the hide, block and suspend actions need a reason and are audited.
 */
class MessageReportService
{
    use AuditsAdminActions;

    public function __construct(
        private readonly ChatService $chat,
        private readonly UserAdminService $users,
    ) {}

    /** The car owner reports a visitor message. A second report of the same message changes nothing. */
    public function reportByOwner(ChatMessage $message, User $owner): MessageReport
    {
        return MessageReport::createOrFirst(['message_id' => $message->id], [
            'reporter_type' => 'owner',
            'reporter_user_id' => $owner->id,
        ]);
    }

    /** The visitor reports an owner message. A second report of the same message changes nothing. */
    public function reportByVisitor(ChatMessage $message, VisitorSession $session): MessageReport
    {
        return MessageReport::createOrFirst(['message_id' => $message->id], [
            'reporter_type' => 'visitor',
            'reporter_visitor_session_id' => $session->id,
        ]);
    }

    /** Nothing wrong with the message: it leaves the open queue. */
    public function dismiss(MessageReport $report, User $admin): void
    {
        $this->resolve($report, $admin, 'dismissed');
    }

    /** The message text disappears for both sides; the row stays so the report keeps its context. */
    public function hideMessage(MessageReport $report, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);
        $message = $report->message;
        if ($message->isHidden()) {
            throw AdminActionException::because('message_already_hidden');
        }

        DB::transaction(function () use ($report, $admin, $reason, $message) {
            $message->update(['hidden_at' => now()]);
            $this->audit($admin, 'chat.hide_message', 'chat_message', $message->id, $reason, ['report_id' => $report->id]);
            $this->resolve($report, $admin, 'hidden');
        });
    }

    /** Blocks the visitor who wrote the message, exactly as the owner's Block button does. */
    public function blockVisitor(MessageReport $report, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);
        $message = $report->message;
        if ($message->sender !== 'visitor') {
            throw AdminActionException::because('report_not_from_visitor');
        }
        $thread = $message->thread;
        if ($thread->isBlocked()) {
            throw AdminActionException::because('visitor_already_blocked');
        }

        DB::transaction(function () use ($report, $admin, $reason, $thread) {
            $this->chat->block($thread);
            $this->audit($admin, 'chat.block_visitor', 'chat_thread', $thread->id, $reason, ['report_id' => $report->id]);
            $this->resolve($report, $admin, 'visitor_blocked');
        });
    }

    /** Suspends the car owner who wrote the message, through the same path as the Users screen. */
    public function suspendOwner(MessageReport $report, User $admin, string $reason): void
    {
        $message = $report->message;
        if ($message->sender !== 'owner') {
            throw AdminActionException::because('report_not_from_owner');
        }
        $owner = $message->thread->car->user;
        if ($owner->isSuspended()) {
            throw AdminActionException::because('owner_already_suspended');
        }

        DB::transaction(function () use ($report, $admin, $reason, $owner) {
            $this->users->suspend($owner, $admin, $reason); // checks the reason and audits
            $this->resolve($report, $admin, 'owner_suspended');
        });
    }

    private function resolve(MessageReport $report, User $admin, string $resolution): void
    {
        $report->update(['reviewed_at' => now(), 'reviewed_by' => $admin->id, 'resolution' => $resolution]);
    }
}

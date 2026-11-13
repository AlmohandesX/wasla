<?php

use App\Exceptions\AdminActionException;
use App\Filament\Resources\ReportedMessageResource\Pages\ManageReportedMessages;
use App\Http\Resources\VisitorMessageResource;
use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\MessageReport;
use App\Services\Chat\ChatService;
use App\Services\Chat\MessageReportService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
    $this->owner = owner(['email_verified_at' => now()]);
    $this->thread = chatThread(linkedCar($this->owner), 'you are an idiot');
    $this->visitorMessage = $this->thread->messages()->first();
    $this->ownerMessage = $this->thread->messages()->create(['sender' => 'owner', 'body' => 'get lost']);
});

function reportByOwner(ChatMessage $message): MessageReport
{
    return app(MessageReportService::class)->reportByOwner($message, $message->thread->car->user);
}

function reportByVisitor(ChatMessage $message): MessageReport
{
    return app(MessageReportService::class)->reportByVisitor($message, $message->thread->visitorSession);
}

function reportAction(string $name, MessageReport $report): TestAction
{
    return TestAction::make($name)->table($report);
}

it('shows open reports by default and reviewed ones on request', function () {
    $open = reportByOwner($this->visitorMessage);
    $done = reportByVisitor($this->ownerMessage);
    app(MessageReportService::class)->dismiss($done, $this->admin);

    Livewire::test(ManageReportedMessages::class)
        ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$done])
        ->filterTable('state', 'reviewed')
        ->assertCanSeeTableRecords([$done])->assertCanNotSeeTableRecords([$open]);
});

it('dismisses a report and records who did it', function () {
    $report = reportByOwner($this->visitorMessage);

    Livewire::test(ManageReportedMessages::class)
        ->callAction(reportAction('dismiss', $report))
        ->assertHasNoActionErrors();

    $report->refresh();
    expect($report->resolution)->toBe('dismissed')
        ->and($report->reviewed_by)->toBe($this->admin->id)
        ->and($report->reviewed_at)->not->toBeNull()
        ->and($this->visitorMessage->fresh()->isHidden())->toBeFalse();
});

it('hides a message for both sides, with a reason, and audits it', function () {
    $report = reportByVisitor($this->ownerMessage);

    Livewire::test(ManageReportedMessages::class)
        ->callAction(reportAction('hideMessage', $report), ['reason' => 'insult'])
        ->assertHasNoActionErrors();

    expect($this->ownerMessage->fresh()->isHidden())->toBeTrue()
        ->and($report->fresh()->resolution)->toBe('hidden')
        ->and(AuditLog::where('action', 'chat.hide_message')->sole()->meta['reason'])->toBe('insult')
        ->and((new VisitorMessageResource($this->ownerMessage->fresh()))->resolve())->toMatchArray(['body' => '', 'hidden' => true]);
});

it('requires a reason to hide a message', function () {
    $report = reportByVisitor($this->ownerMessage);

    Livewire::test(ManageReportedMessages::class)
        ->callAction(reportAction('hideMessage', $report), ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($this->ownerMessage->fresh()->isHidden())->toBeFalse()->and($report->fresh()->isOpen())->toBeTrue();
});

it('blocks the visitor who wrote a reported message', function () {
    $report = reportByOwner($this->visitorMessage);

    Livewire::test(ManageReportedMessages::class)
        ->assertActionHidden(reportAction('suspendOwner', $report))
        ->callAction(reportAction('blockVisitor', $report), ['reason' => 'harassment'])
        ->assertHasNoActionErrors();

    $thread = $this->thread->fresh();
    expect($thread->isBlocked())->toBeTrue()
        ->and(Cache::has(ChatService::cooldownKey($thread->car_id, $thread->visitorSession->ip_hash)))->toBeTrue()
        ->and($report->fresh()->resolution)->toBe('visitor_blocked')
        ->and(AuditLog::where('action', 'chat.block_visitor')->sole()->subject_id)->toBe($thread->id);
});

it('suspends the owner who wrote a reported message', function () {
    $report = reportByVisitor($this->ownerMessage);

    Livewire::test(ManageReportedMessages::class)
        ->assertActionHidden(reportAction('blockVisitor', $report))
        ->callAction(reportAction('suspendOwner', $report), ['reason' => 'threats'])
        ->assertHasNoActionErrors();

    expect($this->owner->fresh()->isSuspended())->toBeTrue()
        ->and($report->fresh()->resolution)->toBe('owner_suspended')
        ->and(AuditLog::where('action', 'user.suspend')->sole()->subject_id)->toBe($this->owner->id);
});

it('refuses actions that do not fit the message', function () {
    $service = app(MessageReportService::class);
    $visitorReport = reportByOwner($this->visitorMessage);
    $ownerReport = reportByVisitor($this->ownerMessage);

    expect(fn () => $service->suspendOwner($visitorReport, $this->admin, 'x'))->toThrow(AdminActionException::class)
        ->and(fn () => $service->blockVisitor($ownerReport, $this->admin, 'x'))->toThrow(AdminActionException::class);

    $service->hideMessage($ownerReport, $this->admin, 'x');
    expect(fn () => $service->hideMessage($ownerReport->fresh(), $this->admin, 'x'))->toThrow(AdminActionException::class)
        ->and($this->owner->fresh()->isSuspended())->toBeFalse()
        ->and($this->thread->fresh()->isBlocked())->toBeFalse();
});

it('removes a report together with its message', function () {
    reportByOwner($this->visitorMessage);
    $this->visitorMessage->delete();

    expect(MessageReport::count())->toBe(0);
});

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportedMessageResource\Pages\ManageReportedMessages;
use App\Filament\Support\AdminActionRunner;
use App\Models\ChatMessage;
use App\Models\MessageReport;
use App\Services\Chat\MessageReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The queue of chat messages an owner or a visitor reported. Open reports show first; each action closes the
 * report with what was done. Admins see the original text even after a message is hidden.
 */
class ReportedMessageResource extends Resource
{
    protected static ?string $model = MessageReport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    public static function getModelLabel(): string
    {
        return __('admin.reported.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.reported.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = MessageReport::query()->whereNull('reviewed_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['message.thread.car.user', 'reporterUser', 'reviewer']);
    }

    public static function table(Table $table): Table
    {
        $reason = fn () => Textarea::make('reason')->label(__('admin.reason'))->required()->maxLength(500);
        $service = fn (): MessageReportService => app(MessageReportService::class);

        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.reported.reported_at'))->dateTime(timezone: 'Europe/Istanbul')->sortable(),
                TextColumn::make('message.body')->label(__('admin.reported.body'))->wrap()
                    ->description(fn (MessageReport $record) => $record->message->isHidden() ? __('admin.reported.hidden') : null),
                TextColumn::make('message.sender')->label(__('admin.reported.sender'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.reported.'.$state))
                    ->description(fn (MessageReport $record) => self::author($record->message)),
                TextColumn::make('reporter_type')->label(__('admin.reported.reporter'))
                    ->formatStateUsing(fn (MessageReport $record) => self::reporter($record)),
                TextColumn::make('message.thread_id')->label(__('admin.reported.thread'))
                    ->description(fn (MessageReport $record) => $record->message->thread->car->model),
                TextColumn::make('message.moderation_status')->label(__('admin.reported.status'))
                    ->formatStateUsing(fn ($state) => __('admin.reported.statuses.'.$state)),
                TextColumn::make('resolution')->label(__('admin.reported.outcome'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.reported.resolutions.'.$state))
                    ->placeholder(__('admin.reported.open'))
                    ->description(fn (MessageReport $record) => $record->reviewer?->name),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('state')->label(__('admin.reported.state'))
                    ->options(['open' => __('admin.reported.open'), 'reviewed' => __('admin.reported.reviewed')])
                    ->default('open')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('reviewed_at'),
                        'reviewed' => $query->whereNotNull('reviewed_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('dismiss')
                    ->label(__('admin.reported.dismiss'))
                    ->requiresConfirmation()
                    ->visible(fn (MessageReport $record) => $record->isOpen())
                    ->action(fn (MessageReport $record) => AdminActionRunner::run(
                        fn () => $service()->dismiss($record, auth()->user()),
                        'admin.reported.dismissed',
                    )),
                Action::make('hideMessage')
                    ->label(__('admin.reported.hide'))
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.reported.hide_confirm'))
                    ->visible(fn (MessageReport $record) => ! $record->message->isHidden())
                    ->schema([$reason()])
                    ->action(fn (MessageReport $record, array $data) => AdminActionRunner::run(
                        fn () => $service()->hideMessage($record, auth()->user(), $data['reason']),
                        'admin.reported.hidden_done',
                    )),
                Action::make('blockVisitor')
                    ->label(__('admin.reported.block_visitor'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.reported.block_confirm'))
                    ->visible(fn (MessageReport $record) => $record->message->sender === 'visitor' && ! $record->message->thread->isBlocked())
                    ->schema([$reason()])
                    ->action(fn (MessageReport $record, array $data) => AdminActionRunner::run(
                        fn () => $service()->blockVisitor($record, auth()->user(), $data['reason']),
                        'admin.reported.blocked',
                    )),
                Action::make('suspendOwner')
                    ->label(__('admin.reported.suspend_owner'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.reported.suspend_confirm'))
                    ->visible(fn (MessageReport $record) => $record->message->sender === 'owner' && ! $record->message->thread->car->user->isSuspended())
                    ->schema([$reason()])
                    ->action(fn (MessageReport $record, array $data) => AdminActionRunner::run(
                        fn () => $service()->suspendOwner($record, auth()->user(), $data['reason']),
                        'admin.reported.suspended',
                    )),
            ]);
    }

    /** Who wrote the message: the owner's account, or the anonymous visitor session. */
    private static function author(ChatMessage $message): string
    {
        $thread = $message->thread;

        return $message->sender === 'owner'
            ? self::account($thread->car->user?->name, $thread->car->user?->email)
            : __('admin.reported.visitor_session', ['id' => $thread->visitor_session_id]);
    }

    private static function reporter(MessageReport $report): string
    {
        return $report->reporter_type === 'owner'
            ? self::account($report->reporterUser?->name, $report->reporterUser?->email)
            : __('admin.reported.visitor_session', ['id' => $report->reporter_visitor_session_id ?? '-']);
    }

    private static function account(?string $name, ?string $email): string
    {
        return $name === null ? __('admin.reported.deleted_account') : "$name ($email)";
    }

    public static function getPages(): array
    {
        return ['index' => ManageReportedMessages::route('/')];
    }
}

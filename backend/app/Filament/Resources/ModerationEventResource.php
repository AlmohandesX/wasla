<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ModerationEventResource\Pages\ManageModerationEvents;
use App\Models\ModerationEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

class ModerationEventResource extends Resource
{
    protected static ?string $model = ModerationEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    public static function getModelLabel(): string
    {
        return __('admin.moderation.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.moderation.plural');
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('admin.moderation.created_at'))->dateTime(timezone: 'Europe/Istanbul')->sortable(),
                TextColumn::make('kind')->label(__('admin.moderation.kind'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.moderation.'.$state)),
                TextColumn::make('surface')->label(__('admin.moderation.surface'))
                    ->formatStateUsing(fn ($state) => Lang::has('admin.moderation.surfaces.'.$state) ? __('admin.moderation.surfaces.'.$state) : $state),
                TextColumn::make('text')->label(__('admin.moderation.text'))->limit(80)->tooltip(fn (ModerationEvent $record) => $record->text),
                TextColumn::make('source')->label(__('admin.moderation.source'))
                    ->formatStateUsing(fn ($state) => Lang::has('admin.moderation.sources.'.$state) ? __('admin.moderation.sources.'.$state) : $state),
                TextColumn::make('reason')->label(__('admin.moderation.reason'))->limit(60),
                // A plain id on purpose: the thread can be missing (an event is written before the thread exists).
                TextColumn::make('thread_id')->label(__('admin.moderation.thread'))->placeholder('-'),
                TextColumn::make('reviewed_at')->label(__('admin.moderation.reviewed_at'))->dateTime(timezone: 'Europe/Istanbul')->placeholder('-'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('kind')->label(__('admin.moderation.kind'))
                    ->options(['blocked' => __('admin.moderation.blocked'), 'flagged' => __('admin.moderation.flagged')]),
                Filter::make('unreviewed')->label(__('admin.moderation.unreviewed'))
                    ->query(fn (Builder $query) => $query->whereNull('reviewed_at')),
            ])
            ->recordActions([
                Action::make('markReviewed')
                    ->label(__('admin.moderation.mark_reviewed'))
                    ->visible(fn (ModerationEvent $record) => $record->reviewed_at === null)
                    ->action(function (ModerationEvent $record) {
                        $record->update(['reviewed_at' => now(), 'reviewed_by' => auth()->id()]);
                        Notification::make()->title(__('admin.moderation.reviewed'))->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageModerationEvents::route('/')];
    }
}

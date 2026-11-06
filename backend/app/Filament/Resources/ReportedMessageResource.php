<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportedMessageResource\Pages\ManageReportedMessages;
use App\Models\ChatMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Chat messages an owner or a visitor reported. Both sides stamp reported_at. */
class ReportedMessageResource extends Resource
{
    protected static ?string $model = ChatMessage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    public static function getModelLabel(): string
    {
        return __('admin.reported.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.reported.plural');
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
        return parent::getEloquentQuery()->whereNotNull('reported_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reported_at')->label(__('admin.reported.reported_at'))->dateTime(timezone: 'Europe/Istanbul')->sortable(),
                TextColumn::make('sender')->label(__('admin.reported.sender'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.reported.'.$state)),
                TextColumn::make('body')->label(__('admin.reported.body'))->wrap(),
                TextColumn::make('thread_id')->label(__('admin.reported.thread')),
                TextColumn::make('moderation_status')->label(__('admin.reported.status'))
                    ->formatStateUsing(fn ($state) => __('admin.reported.statuses.'.$state)),
            ])
            ->defaultSort('reported_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageReportedMessages::route('/')];
    }
}

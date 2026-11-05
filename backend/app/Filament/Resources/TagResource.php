<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TagResource\Pages\ManageTags;
use App\Filament\Support\AdminActionRunner;
use App\Models\Tag;
use App\Services\Tags\TagAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    public static function getModelLabel(): string
    {
        return __('admin.tags.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.tags.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $reason = fn () => Textarea::make('reason')->label(__('admin.reason'))->required()->maxLength(500);
        $service = fn () => app(TagAdminService::class);

        return $table
            ->columns([
                TextColumn::make('serial')->label(__('admin.tags.serial'))->searchable(),
                TextColumn::make('wave.number')->label(__('admin.tags.wave'))->sortable(),
                TextColumn::make('car.plate')->label(__('admin.tags.car'))->placeholder(__('admin.tags.unlinked')),
                TextColumn::make('first_activated_at')->label(__('admin.tags.first_activated_at'))
                    ->dateTime(timezone: 'Europe/Istanbul')->placeholder(__('admin.tags.never')),
                TextColumn::make('expires_at')->label(__('admin.tags.expires_at'))
                    ->dateTime(timezone: 'Europe/Istanbul')->sortable()->placeholder(__('admin.tags.none')),
                TextColumn::make('revoked_at')->label(__('admin.tags.revoked_at'))
                    ->dateTime(timezone: 'Europe/Istanbul')->placeholder(__('admin.tags.valid')),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('replace')
                    ->label(__('admin.tags.replace'))
                    ->visible(fn (Tag $record) => $record->car_id !== null && ! $record->isRevoked())
                    ->requiresConfirmation()
                    ->schema([
                        Select::make('new_tag_id')->label(__('admin.tags.replacement_tag'))->required()
                            ->searchable()
                            ->getOptionLabelUsing(fn ($value) => Tag::whereKey($value)->value('serial'))
                            ->getSearchResultsUsing(fn (string $search) => Tag::query()
                                ->whereNull('car_id')->whereNull('first_activated_at')->whereNull('revoked_at')->whereNull('expires_at')
                                ->where('serial', 'like', '%'.addcslashes($search, '%_\\').'%')
                                ->limit(20)->pluck('serial', 'id')->all()),
                        $reason(),
                    ])
                    ->action(fn (Tag $record, array $data) => AdminActionRunner::run(
                        fn () => $service()->replace($record, Tag::findOrFail($data['new_tag_id']), auth()->user(), $data['reason']),
                        'admin.tags.replaced',
                    )),
                Action::make('forceRelease')
                    ->label(__('admin.tags.force_release'))
                    ->visible(fn (Tag $record) => $record->car_id !== null)
                    ->requiresConfirmation()
                    ->schema([$reason()])
                    ->action(fn (Tag $record, array $data) => AdminActionRunner::run(
                        fn () => $service()->forceRelease($record, auth()->user(), $data['reason']),
                        'admin.tags.released',
                    )),
                Action::make('extend')
                    ->label(__('admin.tags.extend'))
                    ->visible(fn (Tag $record) => $record->first_activated_at !== null && ! $record->isRevoked())
                    ->schema([
                        // The picker converts what was typed in Istanbul time to the app timezone (UTC) itself.
                        DateTimePicker::make('expires_at')->label(__('admin.tags.new_expiry'))->required()->timezone('Europe/Istanbul'),
                        $reason(),
                    ])
                    ->action(fn (Tag $record, array $data) => AdminActionRunner::run(
                        fn () => $service()->extendManually($record, Carbon::parse($data['expires_at']), auth()->user(), $data['reason']),
                        'admin.tags.extended',
                    )),
                Action::make('revoke')
                    ->label(__('admin.tags.revoke'))
                    ->color('danger')
                    ->visible(fn (Tag $record) => ! $record->isRevoked())
                    ->requiresConfirmation()
                    ->schema([$reason()])
                    ->action(fn (Tag $record, array $data) => AdminActionRunner::run(
                        fn () => $service()->revoke($record, auth()->user(), $data['reason']),
                        'admin.tags.revoked',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTags::route('/')];
    }
}

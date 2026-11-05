<?php

namespace App\Filament\Resources;

use App\Exceptions\InvalidReplacementTag;
use App\Filament\Resources\TagResource\Pages\ManageTags;
use App\Models\Tag;
use App\Services\Tags\TagAdminService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->maxLength(500);
        // The service validates under a row lock; this only reports its refusal instead of a server error.
        $guard = function (callable $run) {
            try {
                $run();
            } catch (InvalidReplacementTag|\LogicException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        };

        return $table
            ->columns([
                TextColumn::make('serial')->searchable(),
                TextColumn::make('wave.number')->label('Wave')->sortable(),
                TextColumn::make('car.plate')->label('Car')->placeholder('Unlinked'),
                TextColumn::make('first_activated_at')->dateTime(timezone: 'Europe/Istanbul')->placeholder('Never'),
                TextColumn::make('expires_at')->dateTime(timezone: 'Europe/Istanbul')->sortable()->placeholder('None'),
                TextColumn::make('revoked_at')->dateTime(timezone: 'Europe/Istanbul')->placeholder('Valid'),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Action::make('replace')
                    ->visible(fn (Tag $record) => $record->car_id !== null && ! $record->isRevoked())
                    ->requiresConfirmation()
                    ->form([
                        Select::make('new_tag_id')->label('Replacement tag')->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Tag::query()
                                ->whereNull('car_id')->whereNull('first_activated_at')->whereNull('revoked_at')->whereNull('expires_at')
                                ->where('serial', 'like', '%'.addcslashes($search, '%_\\').'%')
                                ->limit(20)->pluck('serial', 'id')->all()),
                        $reason(),
                    ])
                    ->action(fn (Tag $record, array $data) => $guard(fn () => app(TagAdminService::class)
                        ->replace($record, Tag::findOrFail($data['new_tag_id']), auth()->user(), $data['reason']))),
                Action::make('forceRelease')
                    ->label('Force release')
                    ->visible(fn (Tag $record) => $record->car_id !== null)
                    ->requiresConfirmation()
                    ->form([$reason()])
                    ->action(fn (Tag $record, array $data) => app(TagAdminService::class)->forceRelease($record, auth()->user(), $data['reason'])),
                Action::make('extend')
                    ->label('Set expiry')
                    ->visible(fn (Tag $record) => $record->first_activated_at !== null)
                    ->form([
                        DateTimePicker::make('expires_at')->label('New expiry (Istanbul time)')->required()->timezone('Europe/Istanbul'),
                        $reason(),
                    ])
                    ->action(fn (Tag $record, array $data) => $guard(fn () => app(TagAdminService::class)
                        ->extendManually($record, Carbon::parse($data['expires_at'], 'Europe/Istanbul'), auth()->user(), $data['reason']))),
                Action::make('revoke')
                    ->color('danger')
                    ->visible(fn (Tag $record) => ! $record->isRevoked())
                    ->requiresConfirmation()
                    ->form([$reason()])
                    ->action(fn (Tag $record, array $data) => app(TagAdminService::class)->revoke($record, auth()->user(), $data['reason'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTags::route('/')];
    }
}

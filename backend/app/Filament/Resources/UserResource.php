<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Models\User;
use App\Services\Auth\UserAdminService;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->maxLength(500);

        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->badge(),
                TextColumn::make('suspended_at')->dateTime(timezone: 'Europe/Istanbul')->placeholder('Active'),
                TextColumn::make('created_at')->dateTime(timezone: 'Europe/Istanbul')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Action::make('suspend')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => ! $record->isSuspended() && $record->id !== auth()->id())
                    ->form([$reason()])
                    ->action(fn (User $record, array $data) => app(UserAdminService::class)->suspend($record, auth()->user(), $data['reason'])),
                Action::make('unsuspend')
                    ->visible(fn (User $record) => $record->isSuspended())
                    ->form([$reason()])
                    ->action(fn (User $record, array $data) => app(UserAdminService::class)->unsuspend($record, auth()->user(), $data['reason'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}

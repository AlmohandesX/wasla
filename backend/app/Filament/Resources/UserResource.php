<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Filament\Support\AdminActionRunner;
use App\Models\User;
use App\Services\Auth\UserAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function getModelLabel(): string
    {
        return __('admin.users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.users.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $reason = fn () => Textarea::make('reason')->label(__('admin.reason'))->required()->maxLength(500);

        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.users.name'))->searchable(),
                TextColumn::make('email')->label(__('admin.users.email'))->searchable(),
                TextColumn::make('role')->label(__('admin.users.role'))->badge(),
                TextColumn::make('suspended_at')->label(__('admin.users.suspended_at'))
                    ->dateTime(timezone: 'Europe/Istanbul')->placeholder(__('admin.users.active')),
                TextColumn::make('created_at')->label(__('admin.users.created_at'))
                    ->dateTime(timezone: 'Europe/Istanbul')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('suspend')
                    ->label(__('admin.users.suspend'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => ! $record->isSuspended() && $record->id !== auth()->id())
                    ->schema([$reason()])
                    ->action(fn (User $record, array $data) => AdminActionRunner::run(
                        fn () => app(UserAdminService::class)->suspend($record, auth()->user(), $data['reason']),
                        'admin.users.suspended',
                    )),
                Action::make('unsuspend')
                    ->label(__('admin.users.unsuspend'))
                    ->visible(fn (User $record) => $record->isSuspended())
                    ->schema([$reason()])
                    ->action(fn (User $record, array $data) => AdminActionRunner::run(
                        fn () => app(UserAdminService::class)->unsuspend($record, auth()->user(), $data['reason']),
                        'admin.users.unsuspended',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}

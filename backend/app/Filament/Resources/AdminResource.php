<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminResource\Pages\ManageAdmins;
use App\Models\User;
use App\Services\Auth\AdminAccountService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Web panel accounts. Only a super admin sees this page; it is where new admins get a username and password. */
class AdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'admins';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    public static function getModelLabel(): string
    {
        return __('admin.admins.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.admins.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
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
        return parent::getEloquentQuery()->whereIn('role', User::ADMIN_ROLES);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('admin.admins.name'))->required()->maxLength(80),
            TextInput::make('username')->label(__('admin.admins.username'))->required()
                ->helperText(__('admin.admins.username_help'))
                ->dehydrateStateUsing(fn (?string $state) => User::normalizeUsername((string) $state))
                ->rules([
                    fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (preg_match(User::USERNAME_PATTERN, User::normalizeUsername((string) $value)) !== 1) {
                            $fail(__('admin.admins.username_invalid'));
                        }
                    },
                    // Checked on the normalised name, which is what the unique index holds.
                    fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (User::where('username', User::normalizeUsername((string) $value))->exists()) {
                            $fail(__('admin.admins.username_taken'));
                        }
                    },
                ]),
            TextInput::make('password')->label(__('admin.admins.password'))->required()
                ->password()->revealable()->autocomplete('new-password')
                ->minLength(AdminAccountService::MIN_PASSWORD)->maxLength(128)
                ->confirmed(),
            TextInput::make('password_confirmation')->label(__('admin.admins.password_confirmation'))->required()
                ->password()->revealable()->autocomplete('new-password')->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.admins.name'))->searchable(),
                TextColumn::make('username')->label(__('admin.admins.username'))->searchable(),
                TextColumn::make('role')->label(__('admin.admins.role'))->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.roles.'.$state))
                    ->color(fn (string $state) => $state === 'super_admin' ? 'primary' : 'gray'),
                TextColumn::make('suspended_at')->label(__('admin.users.suspended_at'))
                    ->dateTime(timezone: 'Europe/Istanbul')->placeholder(__('admin.users.active')),
                TextColumn::make('created_at')->label(__('admin.users.created_at'))
                    ->dateTime(timezone: 'Europe/Istanbul')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->createAnother(false)
                    ->successNotificationTitle(__('admin.admins.created'))
                    // The page is a super admin's only, so the service's own check never refuses here.
                    ->using(fn (array $data) => app(AdminAccountService::class)
                        ->create(auth()->user(), $data['name'], $data['username'], $data['password'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdmins::route('/')];
    }
}

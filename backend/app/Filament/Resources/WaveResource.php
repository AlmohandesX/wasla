<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WaveResource\Pages\ManageWaves;
use App\Filament\Support\AdminActionRunner;
use App\Models\Wave;
use App\Services\Tags\WaveService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class WaveResource extends Resource
{
    protected static ?string $model = Wave::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    public static function getModelLabel(): string
    {
        return __('admin.waves.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.waves.plural');
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
        $service = fn () => app(WaveService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'tags as pending_count' => fn (Builder $tags) => $tags->whereNull('sold_at'),
                'tags as sold_count' => fn (Builder $tags) => $tags->whereNotNull('sold_at'),
                'tags as activated_count' => fn (Builder $tags) => $tags->whereNotNull('first_activated_at'),
            ]))
            ->columns([
                TextColumn::make('number')->label(__('admin.waves.number'))->sortable(),
                TextColumn::make('size')->label(__('admin.waves.size')),
                TextColumn::make('status')->label(__('admin.waves.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.waves.statuses.'.$state)),
                TextColumn::make('pending_count')->label(__('admin.waves.pending')),
                TextColumn::make('sold_count')->label(__('admin.waves.sold')),
                TextColumn::make('activated_count')->label(__('admin.waves.activated')),
                TextColumn::make('created_at')->label(__('admin.waves.created_at'))->dateTime(timezone: 'Europe/Istanbul')->sortable(),
            ])
            ->defaultSort('number', 'desc')
            ->headerActions([
                Action::make('generate')
                    ->label(__('admin.waves.generate'))
                    ->schema([
                        TextInput::make('size')->label(__('admin.waves.size'))->required()->integer()
                            ->minValue(1)->maxValue(WaveService::MAX_SIZE),
                    ])
                    ->action(fn (array $data) => AdminActionRunner::run(
                        fn () => $service()->create((int) $data['size'], auth()->user()),
                        'admin.waves.generated',
                    )),
                Action::make('markSerialRangeSold')
                    ->label(__('admin.waves.range'))
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('from')->label(__('admin.waves.from'))->required()->maxLength(20)->placeholder('W001-000001'),
                        TextInput::make('to')->label(__('admin.waves.to'))->required()->maxLength(20)->placeholder('W001-000100'),
                    ])
                    ->action(fn (array $data) => AdminActionRunner::run(
                        fn () => ['count' => $service()->markSerialRangeSold($data['from'], $data['to'], auth()->user())],
                        'admin.waves.range_marked',
                    )),
            ])
            ->recordActions([
                Action::make('markSold')
                    ->label(__('admin.waves.mark_sold'))
                    ->requiresConfirmation()
                    ->action(fn (Wave $record) => AdminActionRunner::run(
                        fn () => ['count' => $service()->markSold($record, auth()->user())],
                        'admin.waves.marked',
                    )),
                // A one-minute signed link to an admin-only route: the file is on the private disk and has no public URL,
                // and a link another site tricks the browser into following is unsigned or expired.
                Action::make('download')
                    ->label(__('admin.waves.download'))
                    ->visible(fn (Wave $record) => $record->status === 'ready' && $record->package_path !== null)
                    ->action(fn (Wave $record) => redirect(URL::temporarySignedRoute('admin.waves.package', now()->addMinute(), ['wave' => $record->id]))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWaves::route('/')];
    }
}

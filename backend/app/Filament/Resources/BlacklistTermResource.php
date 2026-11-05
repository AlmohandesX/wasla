<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlacklistTermResource\Pages\ManageBlacklistTerms;
use App\Models\BlacklistTerm;
use App\Services\Moderation\TextNormalizer;
use BackedEnum;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BlacklistTermResource extends Resource
{
    protected static ?string $model = BlacklistTerm::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    public static function getModelLabel(): string
    {
        return __('admin.blacklist.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.blacklist.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('term')->label(__('admin.blacklist.term'))->required()->maxLength(120)
                ->rule(fn (Get $get, ?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
                    // Duplicates are judged on the normalised form, which is what the unique index holds.
                    $normalized = (new TextNormalizer)->normalize((string) $value);
                    if ($normalized === '') {
                        $fail(__('admin.blacklist.empty_term'));

                        return;
                    }

                    $exists = BlacklistTerm::query()
                        ->where('normalized', $normalized)
                        ->where('match_mode', $get('match_mode'))
                        ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->exists();

                    if ($exists) {
                        $fail(__('admin.blacklist.duplicate'));
                    }
                }),
            Select::make('match_mode')->label(__('admin.blacklist.match_mode'))->required()->default('word')->live()
                ->options(['word' => __('admin.blacklist.word'), 'substring' => __('admin.blacklist.substring')]),
            Toggle::make('active')->label(__('admin.blacklist.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term')->label(__('admin.blacklist.term'))->searchable(),
                TextColumn::make('match_mode')->label(__('admin.blacklist.match_mode'))
                    ->formatStateUsing(fn ($state) => __('admin.blacklist.'.$state)),
                IconColumn::make('active')->label(__('admin.blacklist.active'))->boolean(),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBlacklistTerms::route('/')];
    }
}

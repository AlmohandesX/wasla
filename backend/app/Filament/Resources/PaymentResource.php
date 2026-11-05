<?php

namespace App\Filament\Resources;

use App\Enums\PaymentStatus;
use App\Filament\Resources\PaymentResource\Pages\ManagePayments;
use App\Filament\Support\AdminActionRunner;
use App\Models\Payment;
use App\Services\Payments\Kurus;
use App\Services\Payments\RenewalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    public static function getModelLabel(): string
    {
        return __('admin.payments.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.payments.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** At most the last 2 digits, behind a fixed-length mask so the length is not shown either. */
    public static function maskNationalId(?string $nationalId): ?string
    {
        return $nationalId === null || $nationalId === '' ? null : str_repeat('*', 9).substr($nationalId, -2);
    }

    public static function table(Table $table): Table
    {
        $reason = fn () => Textarea::make('reason')->label(__('admin.reason'))->required()->maxLength(500);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['tag:id,serial', 'user:id,email']))
            ->columns([
                TextColumn::make('reference')->label(__('admin.payments.reference'))->searchable(),
                TextColumn::make('provider')->label(__('admin.payments.provider')),
                TextColumn::make('status')->label(__('admin.payments.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('admin.payments.statuses.'.($state instanceof PaymentStatus ? $state->value : $state))),
                TextColumn::make('amount_kurus')->label(__('admin.payments.amount'))
                    ->formatStateUsing(fn ($state, Payment $record) => Kurus::toDecimal((int) $state).' '.$record->currency),
                TextColumn::make('tag.serial')->label(__('admin.payments.tag'))->searchable(),
                TextColumn::make('user.email')->label(__('admin.payments.user'))->searchable(),
                TextColumn::make('paid_at')->label(__('admin.payments.paid_at'))->dateTime(timezone: 'Europe/Istanbul')->sortable(),
                TextColumn::make('refunded_at')->label(__('admin.payments.refunded_at'))->dateTime(timezone: 'Europe/Istanbul')->toggleable(),
                TextColumn::make('flag_reason')->label(__('admin.payments.flag'))->badge()->color('warning'),
                TextColumn::make('flag_reviewed_at')->label(__('admin.payments.flag_reviewed_at'))->dateTime(timezone: 'Europe/Istanbul')->toggleable(),
                // The full number exists only in the CSV export. Never searchable: it is encrypted, and a search would need it in clear.
                TextColumn::make('billing_national_id')->label(__('admin.payments.national_id'))
                    ->formatStateUsing(fn ($state) => self::maskNationalId($state))->searchable(false)->sortable(false),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label(__('admin.payments.status'))
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => __('admin.payments.statuses.'.$s->value)])->all()),
                Filter::make('unreviewed_flags')->label(__('admin.payments.unreviewed_flags'))
                    ->query(fn (Builder $query) => $query->whereNotNull('flag_reason')->whereNull('flag_reviewed_at')),
            ])
            ->headerActions([
                // Opens an admin-only streamed download; the file is never built in memory.
                Action::make('export')
                    ->label(__('admin.payments.export'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.payments.export_from'))->required()->native(false),
                        DatePicker::make('to')->label(__('admin.payments.export_to'))->required()->native(false)->afterOrEqual('from'),
                    ])
                    ->action(fn (array $data) => redirect()->route('admin.payments.export', ['from' => $data['from'], 'to' => $data['to']])),
            ])
            ->recordActions([
                Action::make('refund')
                    ->label(__('admin.payments.refund'))
                    ->color('danger')
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Paid)
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.payments.refund_confirm'))
                    ->schema([$reason()])
                    ->action(fn (Payment $record, array $data) => AdminActionRunner::run(
                        fn () => app(RenewalService::class)->markRefunded($record, auth()->user(), $data['reason']),
                        'admin.payments.refunded',
                    )),
                Action::make('markReviewed')
                    ->label(__('admin.payments.mark_reviewed'))
                    ->visible(fn (Payment $record) => $record->flag_reason !== null && $record->flag_reviewed_at === null)
                    ->schema([$reason()])
                    ->action(fn (Payment $record, array $data) => AdminActionRunner::run(
                        fn () => app(RenewalService::class)->markFlagReviewed($record, auth()->user(), $data['reason']),
                        'admin.payments.flag_reviewed',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePayments::route('/')];
    }
}

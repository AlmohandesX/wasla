<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\Payments\Kurus;
use App\Services\Payments\RenewalService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.title');
    }

    public function getTitle(): string
    {
        return __('admin.settings.title');
    }

    public function mount(): void
    {
        $current = Setting::value(RenewalService::PRICE_SETTING);

        $this->form->fill(['price' => $current !== null && ctype_digit($current) ? self::formatLira((int) $current) : '']);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('price')->label(__('admin.settings.price'))->helperText(__('admin.settings.price_help'))
                ->required()->maxLength(20)->inputMode('decimal')
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                    $kurus = Kurus::fromDecimal(is_string($value) ? trim($value) : null);
                    if ($kurus === null || $kurus <= 0 || $kurus > RenewalService::MAX_KURUS) {
                        $fail(__('admin.price_invalid'));
                    }
                }),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('admin.settings.save'))->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        // Validated above: exact text to integer kurus, no floats.
        $kurus = (int) Kurus::fromDecimal(trim($data['price']));

        DB::transaction(function () use ($kurus) {
            $old = Setting::query()->lockForUpdate()->find(RenewalService::PRICE_SETTING)?->value;
            Setting::query()->updateOrCreate(['key' => RenewalService::PRICE_SETTING], ['value' => (string) $kurus]);
            AuditLog::create([
                'admin_id' => auth()->id(),
                'action' => 'setting.price',
                'subject_type' => 'setting',
                'subject_id' => 0,
                'meta' => ['from_kurus' => $old, 'to_kurus' => $kurus, 'reason' => 'price changed'],
            ]);
        });

        Notification::make()->title(__('admin.settings.saved'))->success()->send();
    }

    private static function formatLira(int $kurus): string
    {
        return str_replace('.', ',', Kurus::toDecimal($kurus));
    }
}

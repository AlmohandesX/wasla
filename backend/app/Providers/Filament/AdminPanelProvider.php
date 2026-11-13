<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Settings;
use App\Filament\Resources\AdminResource;
use App\Filament\Resources\BlacklistTermResource;
use App\Filament\Resources\ModerationEventResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ReportedMessageResource;
use App\Filament\Resources\TagResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WaveResource;
use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TagActivationsChart;
use App\Filament\Widgets\UserRegistrationsChart;
use App\Http\Middleware\SecurityHeaders;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Filament puts buttons and links on shade 600; generating a palette from #14213D would make 600 a mid blue, so
     * the shades are set by hand with the brand navy at 600 (15.6:1 with white text).
     */
    private const NAVY = [
        50 => '#F2F4F8', 100 => '#E8EBF2', 200 => '#C9D0DF', 300 => '#9AA6C0', 400 => '#6A7AA0', 500 => '#3D4F7A',
        600 => '#14213D', 700 => '#0F1A31', 800 => '#0B1428', 900 => '#080F1E', 950 => '#050A14',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            // Beni Bul brand (brand/README.md): navy as the one accent colour, the logo, and Manrope.
            ->brandName('Beni Bul')
            ->brandLogo(asset('img/beni-bul-logo.svg'))
            // Larger on the sign-in card, compact in the top bar.
            ->brandLogoHeight(fn (): string => request()->routeIs('filament.admin.auth.login') ? '5.5rem' : '2.75rem')
            ->favicon(asset('img/favicon.png'))
            ->colors(['primary' => self::NAVY, 'gray' => Color::Slate])
            ->darkMode(false)
            // Filament's default avatar and font come from third-party hosts the admin CSP does not allow, so the
            // avatar is drawn locally and Manrope is served from /fonts.
            ->font('Manrope', url: asset('css/admin-font.css'), provider: LocalFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->resources([
                UserResource::class, AdminResource::class, TagResource::class, WaveResource::class, PaymentResource::class,
                BlacklistTermResource::class, ModerationEventResource::class, ReportedMessageResource::class,
            ])
            ->pages([Dashboard::class, Settings::class])
            ->widgets([StatsOverview::class, UserRegistrationsChart::class, TagActivationsChart::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SecurityHeaders::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}

<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\TagResource;
use App\Filament\Resources\UserResource;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\SecurityHeaders;
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
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('WASLA')
            // White surfaces with royal blue as the one accent colour.
            ->colors(['primary' => Color::hex('#4169E1'), 'gray' => Color::Slate])
            ->darkMode(false)
            // Filament's default avatar and font come from third-party hosts the admin CSP does not allow.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->resources([UserResource::class, TagResource::class])
            ->pages([Dashboard::class])
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

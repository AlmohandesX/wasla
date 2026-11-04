<?php

namespace App\Providers;

use App\Support\ProductionGuard;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProductionGuard::validate(config('wasla.guard_environment'));

        $proxies = trim((string) config('wasla.trusted_proxies'));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
            config(['session.secure' => true]);
        }
    }
}

<?php

namespace App\Providers;

use App\Services\Moderation\ModerationProvider;
use App\Services\Moderation\OpenAiModerationProvider;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\PaymentGateway;
use App\Support\ProductionGuard;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ModerationProvider::class, OpenAiModerationProvider::class);
        $this->app->singleton(GatewayManager::class);
        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(GatewayManager::class)->driver());
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

        $this->configureRateLimiting();

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new MailMessage)
            ->subject(__('wasla.verify_mail_subject'))
            ->line(__('wasla.verify_mail_intro'))
            ->action(__('wasla.verify_mail_action'), $url)
            ->line(__('wasla.verify_mail_outro')));

        // The mobile app handles the reset screen; the link carries the token and email.
        ResetPassword::toMailUsing(fn (object $notifiable, string $token) => (new MailMessage)
            ->subject(__('wasla.reset_mail_subject'))
            ->line(__('wasla.reset_mail_intro'))
            ->action(__('wasla.reset_mail_action'), config('wasla.password_reset_url')
                .'?'.http_build_query(['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]))
            ->line(__('wasla.reset_mail_outro')));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('claim', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Owner polling reads chats often; separate from the 30/min write limit.
        RateLimiter::for('reads', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Starting a payment opens a hosted checkout at the provider: 5 a minute and 20 an hour per user.
        RateLimiter::for('payment-start', fn (Request $request) => [
            Limit::perMinute(5)->by('pay:'.($request->user()?->getAuthIdentifier() ?: $request->ip())),
            Limit::perHour(20)->by('pay-h:'.($request->user()?->getAuthIdentifier() ?: $request->ip())),
        ]);

        // Provider retries come from a few addresses, so this is generous; the signature does the real gating.
        RateLimiter::for('payment-callback', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        RateLimiter::for('scan', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('contact-reveal', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Visitor chat: one open page polls 12 times a minute, so 60 leaves room for a shared IP.
        RateLimiter::for('chat-read', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('chat-write', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('forgot-password', fn (Request $request) => [
            Limit::perHour(3)->by('fp:'.hash('sha256', mb_strtolower((string) $request->input('email')))),
            Limit::perHour(10)->by('fp-ip:'.$request->ip()),
        ]);

        RateLimiter::for('resend-verification', fn (Request $request) => Limit::perMinute(3)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}

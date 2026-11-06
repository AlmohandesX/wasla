<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;

it('sends security headers on web responses', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy');
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("script-src 'self'")->not->toContain('unsafe-inline')->not->toContain('unsafe-eval');
});

it('loosens the CSP for the admin panel only', function () {
    Route::middleware('web')->get('/admin/probe', fn () => 'ok');

    $csp = $this->get('/admin/probe')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("'unsafe-eval'");
});

it('sends HSTS only over https', function () {
    $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
});

it('allows no cross-origin request by default', function () {
    $this->withHeaders(['Origin' => 'https://evil.test'])
        ->get('/api/ping')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    $this->withHeaders([
        'Origin' => 'https://evil.test',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/ping')->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('marks session cookies HttpOnly and SameSite=Lax', function () {
    $response = $this->get('/');

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('runs the guard on boot and refuses an unsafe production config', function () {
    config(['wasla.guard_environment' => productionEnv(['APP_DEBUG' => 'true'])]);

    (new AppServiceProvider($this->app))->boot();
})->throws(RuntimeException::class, 'APP_DEBUG');

it('forces https and secure cookies in production', function () {
    config(['wasla.guard_environment' => productionEnv()]);
    $this->app['env'] = 'production';

    (new AppServiceProvider($this->app))->boot();

    expect(config('session.secure'))->toBeTrue()
        ->and(url('/x'))->toStartWith('https://');
});

it('lists every variable that the WASLA and Sanctum config read in .env.example', function () {
    $example = file_get_contents(base_path('.env.example'));
    foreach (['sanctum', 'wasla'] as $file) {
        preg_match_all("/env\('([A-Z0-9_]+)'/", file_get_contents(config_path("$file.php")), $m);
        foreach ($m[1] as $name) {
            expect(preg_match('/^#? ?'.$name.'=/m', $example))->toBe(1, "$name is missing from .env.example");
        }
    }
});

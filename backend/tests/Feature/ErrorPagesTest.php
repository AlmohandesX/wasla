<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

// Symfony's test requests send Accept-Language: en-us unless told otherwise; an empty one means Turkish.
const TURKISH = ['Accept-Language' => ''];

const STRICT_CSP_START = "default-src 'self'; script-src 'self'; style-src 'self';";

/** A page that renders under the strict CSP: styled by the visitor stylesheet, no inline script or style. */
function assertPlainPage(TestResponse $response): void
{
    $html = $response->getContent();
    expect($html)->toContain('/css/visitor.css')
        ->not->toContain('<style')->not->toContain('<script')->not->toContain(' style=')
        ->not->toContain('Laravel')
        ->and($response->headers->get('Content-Security-Policy'))->toStartWith(STRICT_CSP_START)
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
}

function assertErrorPage(TestResponse $response): void
{
    assertPlainPage($response);
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
}

it('shows a translated 404 for an unknown or malformed token', function () {
    $tr = $this->get('/t/'.str_repeat('a', 16), TURKISH)->assertNotFound();
    assertErrorPage($tr);
    $tr->assertSee(__('pages.404_title', [], 'tr'));

    $en = $this->get('/t/short', ['Accept-Language' => 'en-GB,en;q=0.8'])->assertNotFound();
    assertErrorPage($en);
    $en->assertSee(__('pages.404_title', [], 'en'));
});

it('shows a translated 404 for a path that matches no route', function () {
    $r = $this->get('/no-such-page', TURKISH)->assertNotFound();
    assertErrorPage($r);
    $r->assertSee(__('pages.404_title', [], 'tr'))->assertSee(__('pages.404_body', [], 'tr'));
});

it('shows a translated 429 with the visitor and security headers when the scan limit is hit', function () {
    $tag = tagFixture();
    for ($i = 0; $i < 60; $i++) {
        $this->get("/t/{$tag->token}");
    }

    $r = $this->get("/t/{$tag->token}", ['Accept-Language' => 'en'])->assertStatus(429);
    assertErrorPage($r);
    $r->assertSee(__('pages.429_title', [], 'en'));
    expect($r->headers->has('Retry-After'))->toBeTrue()
        ->and($r->headers->get('Vary'))->toContain('Accept-Language');
});

it('keeps JSON answers for the API and the reveal endpoint', function () {
    $this->getJson('/api/v1/no-such-route')->assertNotFound()->assertHeader('Content-Type', 'application/json');
});

it('explains an expired verification link in the visitor language', function () {
    $user = owner(['email_verified_at' => null]);
    $url = URL::temporarySignedRoute('verification.verify', now()->addHours(24), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->travel(25)->hours();

    $r = $this->get($url, TURKISH)->assertForbidden();
    assertErrorPage($r);
    $r->assertSee(__('pages.link_expired_title', [], 'tr'))->assertSee(__('pages.link_expired_body', [], 'tr'));
});

it('shows a translated plain 403, 500 and 503', function (int $status, string $key) {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_test/fail/{status}', function (int $status) {
        if ($status === 500) {
            throw new RuntimeException('secret internals');
        }
        abort($status);
    });

    $r = $this->get("/_test/fail/$status", ['Accept-Language' => 'en'])->assertStatus($status);
    assertErrorPage($r);
    $r->assertSee(__("pages.{$key}_title", [], 'en'))->assertDontSee('secret internals');
})->with([
    '403' => [403, '403'],
    '500' => [500, '500'],
    '503' => [503, '503'],
]);

it('serves a minimal translated WASLA page at the root instead of the framework welcome page', function () {
    $tr = $this->get('/', TURKISH)->assertOk();
    assertPlainPage($tr);
    $tr->assertSee('WASLA')->assertSee(__('pages.home_body', [], 'tr'));

    $this->get('/', ['Accept-Language' => 'en'])->assertOk()->assertSee(__('pages.home_body', [], 'en'));
});

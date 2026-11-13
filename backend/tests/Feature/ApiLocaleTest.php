<?php

// Headers are passed per request: the test client keeps headers set with withHeader(s) for later requests, and it
// sends an English Accept-Language by default, so "no preference" is an explicit empty header.
const LOGIN_BAD = ['email' => 'nobody@example.com', 'password' => 'x'];

it('picks the locale from Accept-Language', function () {
    $this->postJson('/api/v1/auth/login', LOGIN_BAD, ['Accept-Language' => 'en-US,en;q=0.9'])
        ->assertUnauthorized()->assertJsonPath('message', 'The email or password is incorrect.');

    $this->postJson('/api/v1/auth/login', LOGIN_BAD, ['Accept-Language' => 'tr'])
        ->assertUnauthorized()->assertJsonPath('message', 'E-posta veya şifre hatalı.');

    $this->postJson('/api/v1/auth/login', LOGIN_BAD, ['Accept-Language' => 'fr;q=0.9, en;q=0.5'])
        ->assertUnauthorized()->assertJsonPath('message', 'The email or password is incorrect.');
});

it('falls back to the signed-in owner locale, then English', function () {
    $user = owner(['email_verified_at' => null, 'locale' => 'tr']);
    $turkish = 'Bu işlem için e-posta adresinizi doğrulamanız gerekir.';

    $this->getJson('/api/v1/cars', bearer($user) + ['Accept-Language' => ''])
        ->assertForbidden()->assertJsonPath('message', $turkish);

    // An unsupported language is not a preference: the owner's own locale applies.
    $this->getJson('/api/v1/cars', bearer($user) + ['Accept-Language' => 'fr'])
        ->assertForbidden()->assertJsonPath('message', $turkish);

    // An explicit supported language wins over the saved one.
    $this->getJson('/api/v1/cars', bearer($user) + ['Accept-Language' => 'en'])
        ->assertForbidden()->assertJsonPath('message', 'You need to verify your email address for this action.');

    app('auth')->forgetGuards(); // one process serves every request here; a real request starts clean
    $this->postJson('/api/v1/auth/login', LOGIN_BAD, ['Accept-Language' => ''])
        ->assertJsonPath('message', 'The email or password is incorrect.');
    $this->postJson('/api/v1/auth/login', LOGIN_BAD, ['Accept-Language' => 'ar'])
        ->assertJsonPath('message', 'The email or password is incorrect.');
});

it('translates validation messages and the privacy_consent attribute', function () {
    $body = ['name' => 'A', 'email' => 'a@example.com', 'password' => 'longenough1'];

    $en = $this->postJson('/api/v1/auth/register', $body, ['Accept-Language' => 'en'])->assertUnprocessable();
    expect($en->json('errors.privacy_consent.0'))->toContain('privacy notice')->not->toContain('privacy consent');

    $tr = $this->postJson('/api/v1/auth/register', $body, ['Accept-Language' => 'tr'])->assertUnprocessable();
    expect($tr->json('errors.privacy_consent.0'))->toContain('gizlilik')->not->toContain('privacy');
});

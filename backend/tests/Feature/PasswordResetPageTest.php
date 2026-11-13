<?php

// The test client sends an English Accept-Language by default; ask for Turkish so the Turkish copy is pinned.
// English, the default, is covered by its own test below.
beforeEach(fn () => $this->withHeader('Accept-Language', 'tr'));

function resetLink(?string $email = null): array
{
    $user = owner($email ? ['email' => $email] : []);

    return [$user, app('auth.password.broker')->createToken($user)];
}

it('shows the reset form in English when no language is asked for', function () {
    [$user, $token] = resetLink();

    $this->withHeader('Accept-Language', '')
        ->get('/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]))
        ->assertOk()->assertSee('<html lang="en">', false)->assertDontSee('Yeni şifre');
});

it('shows the reset form in Turkish with strict headers', function () {
    [$user, $token] = resetLink();

    $response = $this->get('/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]))
        ->assertOk()
        ->assertSee('Yeni şifre')
        ->assertSee('name="token"', false)
        ->assertSee('name="password_confirmation"', false)
        ->assertSee(e('wasla://reset-password?'.http_build_query(['token' => $token, 'email' => $user->email])), false);

    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")
        ->not->toContain('unsafe-inline');

    $html = $response->getContent();
    expect($html)->not->toContain('<script')->not->toContain('style="')->not->toContain('onclick');
});

it('shows the form in English when asked', function () {
    [$user, $token] = resetLink();

    $this->withHeader('Accept-Language', 'en')
        ->get('/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]))
        ->assertOk()->assertSee('New password')->assertSee('Open the app');
});

it('shows no form when the link is incomplete', function () {
    $this->get('/reset-password')->assertStatus(422)->assertDontSee('name="password"', false);
});

it('resets the password, revokes tokens and refuses the token a second time', function () {
    [$user, $token] = resetLink();
    $user->createToken('mobile');

    $this->post('/reset-password', [
        'email' => $user->email, 'token' => $token,
        'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
    ])->assertOk()->assertSee('Şifreniz güncellendi');

    expect($user->tokens()->count())->toBe(0)
        ->and(Hash::check('brand-new-pass-1', $user->fresh()->password))->toBeTrue();
    login($user->email, 'brand-new-pass-1', '10.0.0.9')->assertOk();

    $this->post('/reset-password', [
        'email' => $user->email, 'token' => $token,
        'password' => 'another-pass-2', 'password_confirmation' => 'another-pass-2',
    ])->assertStatus(422)->assertSee('geçersiz veya süresi dolmuş');
});

it('gives the same generic error for a wrong token and an unknown email', function () {
    [$user] = resetLink();

    $wrong = $this->post('/reset-password', [
        'email' => $user->email, 'token' => 'nope', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
    ]);
    $unknown = $this->post('/reset-password', [
        'email' => 'nobody@example.com', 'token' => 'nope', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
    ]);

    $wrong->assertStatus(422)->assertSee('geçersiz veya süresi dolmuş');
    expect($unknown->status())->toBe(422)->and(Hash::check(PW, $user->fresh()->password))->toBeTrue();
});

it('validates the password the same way as the API and needs a matching confirmation', function () {
    [$user, $token] = resetLink();

    $this->post('/reset-password', [
        'email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertStatus(422)->assertSee('en az 8');

    $this->post('/reset-password', [
        'email' => $user->email, 'token' => $token, 'password' => 'brand-new-pass-1', 'password_confirmation' => 'different-pass-1',
    ])->assertStatus(422);

    expect(Hash::check(PW, $user->fresh()->password))->toBeTrue();
});

it('does not flash the token or password into the session on a failed attempt', function () {
    [$user, $token] = resetLink();

    $this->post('/reset-password', [
        'email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionMissing('_old_input');
});

it('is throttled', function () {
    foreach (range(1, 10) as $i) {
        $this->get('/reset-password?token=a&email=b%40example.com');
    }

    $this->get('/reset-password?token=a&email=b%40example.com')->assertStatus(429);
});

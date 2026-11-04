<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

const PW = 'correct-horse-9';

function register(array $overrides = [])
{
    return test()->postJson('/api/v1/auth/register', array_merge([
        'name' => 'Ayse Yilmaz',
        'email' => 'ayse@example.com',
        'password' => PW,
        'locale' => 'tr',
        'privacy_consent' => true,
    ], $overrides));
}

function login(string $email, string $password = PW, string $ip = '10.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function owner(array $attrs = []): User
{
    return User::factory()->create(array_merge(['password' => PW], $attrs));
}

function bearer(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

it('registers only with privacy consent', function () {
    register(['privacy_consent' => false])->assertUnprocessable()->assertJsonValidationErrors('privacy_consent');
    register(['privacy_consent' => null])->assertUnprocessable();

    register()->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email', 'email_verified']]);

    $user = User::where('email', 'ayse@example.com')->first();
    expect($user->privacy_accepted_at)->not->toBeNull()
        ->and($user->privacy_notice_version)->toBe(config('wasla.privacy_notice_version'))
        ->and($user->role)->toBe('owner')
        ->and($user->locale)->toBe('tr');
});

it('logs in and returns a token', function () {
    $user = owner();

    $response = login($user->email)->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email_verified']]);

    $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
        ->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', $user->email);
    expect($user->tokens()->first()->expires_at)->not->toBeNull();
});

it('locks after 5 failures for 15 minutes', function () {
    $user = owner();

    foreach (range(1, 5) as $i) {
        login($user->email, 'wrong-password')->assertUnauthorized();
    }

    login($user->email)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');

    $this->travel(15)->minutes();
    $this->travel(1)->seconds();
    login($user->email)->assertOk();
});

it('locks by IP across different emails', function () {
    foreach (range(1, 5) as $i) {
        login("user{$i}@example.com", 'wrong-password', '10.0.0.9')->assertUnauthorized();
    }

    login('user6@example.com', 'wrong-password', '10.0.0.9')->assertStatus(429);
    login('user6@example.com', 'wrong-password', '10.0.0.10')->assertUnauthorized();
});

it('gives the same answer for an unknown email and a wrong password', function () {
    $user = owner();

    $known = login($user->email, 'wrong-password', '10.0.0.2');
    $unknown = login('nobody@example.com', 'wrong-password', '10.0.0.3');

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json())->toBe($known->json());
});

it('counts failures for unknown emails', function () {
    foreach (range(1, 5) as $i) {
        login('Ghost@Example.com', 'x', "10.0.1.{$i}")->assertUnauthorized();
    }

    login('ghost@example.com', 'x', '10.0.1.99')->assertStatus(429);
});

it('does not reset the IP counter on success', function () {
    $attacker = owner();

    foreach (range(1, 4) as $i) {
        login("victim{$i}@example.com", 'x', '10.0.2.1')->assertUnauthorized();
    }
    login($attacker->email, PW, '10.0.2.1')->assertOk();
    login('victim5@example.com', 'x', '10.0.2.1')->assertUnauthorized();

    login($attacker->email, PW, '10.0.2.1')->assertStatus(429);
});

it('rejects login for a suspended user', function () {
    $user = owner(['suspended_at' => now()]);

    login($user->email)->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('rejects an existing token after suspension', function () {
    $user = owner();
    $headers = bearer($user);
    $user->forceFill(['suspended_at' => now()])->save();

    $this->getJson('/api/v1/me', $headers)->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('throttles forgot password and never reveals if the email exists', function () {
    $user = owner();

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

    $known->assertOk();
    expect($unknown->status())->toBe($known->status())->and($unknown->json())->toBe($known->json());

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => strtoupper($user->email)])->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(429);
});

it('resets the password with a valid token', function () {
    $user = owner();
    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email, 'token' => $token, 'password' => 'brand-new-pass-1',
    ])->assertOk();

    login($user->email, 'brand-new-pass-1')->assertOk();
});

it('sends a verification email on registration', function () {
    Notification::fake();

    register()->assertCreated();

    Notification::assertSentTo(User::where('email', 'ayse@example.com')->first(), VerifyEmail::class);
});

it('rejects owner routes with 403 email_not_verified until verified', function () {
    $user = owner(['email_verified_at' => null]);
    $headers = bearer($user);

    $this->getJson('/api/v1/owner/ping', $headers)->assertForbidden()->assertJsonPath('code', 'email_not_verified');
    $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('email_verified', false);
    $this->postJson('/api/v1/auth/email/resend', [], $headers)->assertOk();

    $user->markEmailAsVerified();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/owner/ping', bearer($user->refresh()))->assertOk();
});

it('verifies through the signed link and rejects a tampered or expired one', function () {
    $user = owner(['email_verified_at' => null]);
    $params = ['id' => $user->id, 'hash' => sha1($user->email)];

    $tampered = URL::temporarySignedRoute('verification.verify', now()->addHours(24), $params).'x';
    $this->get($tampered)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addHours(24), ['id' => $user->id, 'hash' => 'bad']);
    $this->get($wrongHash)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $expired = URL::temporarySignedRoute('verification.verify', now()->addHours(24), $params);
    $this->travel(25)->hours();
    $this->get($expired)->assertForbidden();
    $this->travelBack();

    $valid = URL::temporarySignedRoute('verification.verify', now()->addHours(24), $params);
    $this->get($valid)->assertOk()->assertSee(__('wasla.email_verified_title'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('uses a 24 hour expiry for emailed verification links', function () {
    expect(config('auth.verification.expire'))->toBe(1440);
});

it('throttles resend', function () {
    $user = owner(['email_verified_at' => null]);
    $headers = bearer($user);

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/auth/email/resend', [], $headers)->assertOk();
    }

    $this->postJson('/api/v1/auth/email/resend', [], $headers)->assertStatus(429);
});

it('logs out by revoking the current token', function () {
    $user = owner();
    $token = $user->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

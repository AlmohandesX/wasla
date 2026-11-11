<?php

use App\Models\User;
use App\Services\Auth\Google\GoogleTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    fakeGoogle();
});

function googleLogin(array $body)
{
    return test()->postJson('/api/v1/auth/google', $body);
}

it('asks a new Google user to accept the privacy notice before creating anything', function () {
    googleLogin(['id_token' => googleIdToken()])
        ->assertStatus(422)->assertJsonPath('code', 'privacy_consent_required');

    expect(User::count())->toBe(0);
});

it('creates a verified owner with no password from a Google token and returns a working session', function () {
    $response = googleLogin(['id_token' => googleIdToken(), 'privacy_consent' => true, 'locale' => 'en'])
        ->assertCreated()
        ->assertJsonPath('user.name', 'Ayşe Yılmaz')
        ->assertJsonPath('user.email', 'ayse@gmail.com')
        ->assertJsonPath('user.email_verified', true)
        ->assertJsonPath('user.has_password', false)
        ->assertJsonPath('user.google_linked', true);

    $user = User::where('email', 'ayse@gmail.com')->first();
    expect($user->role)->toBe('owner')->and($user->password)->toBeNull()
        ->and($user->google_id)->toBe('1098765432100')->and($user->locale)->toBe('en')
        ->and($user->privacy_accepted_at)->not->toBeNull();

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$response->json('token')])
        ->assertOk()->assertJsonPath('name', 'Ayşe Yılmaz');
});

it('signs a returning Google user in to the same account', function () {
    googleLogin(['id_token' => googleIdToken(), 'privacy_consent' => true])->assertCreated();

    // Google may report a new name or email later: the account is found by its Google ID.
    googleLogin(['id_token' => googleIdToken(['name' => 'Ayşe Y.'])])->assertOk()->assertJsonPath('user.name', 'Ayşe Yılmaz');

    expect(User::count())->toBe(1);
});

it('links Google to an existing password account with the same verified email', function () {
    $owner = owner(['email' => 'ayse@gmail.com', 'email_verified_at' => null]);

    googleLogin(['id_token' => googleIdToken()])->assertOk()
        ->assertJsonPath('user.id', $owner->id)->assertJsonPath('user.has_password', true);

    $owner->refresh();
    expect($owner->google_id)->toBe('1098765432100')->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and(User::count())->toBe(1);

    // The password keeps working.
    $this->postJson('/api/v1/auth/login', ['email' => 'ayse@gmail.com', 'password' => PW])->assertOk();
});

it('never re-links an email that belongs to a different Google account', function () {
    $owner = owner(['email' => 'ayse@gmail.com', 'google_id' => 'someone-else']);

    googleLogin(['id_token' => googleIdToken()])->assertStatus(409)->assertJsonPath('code', 'google_account_conflict');

    expect($owner->fresh()->google_id)->toBe('someone-else');
});

it('refuses panel accounts and suspended owners', function () {
    adminUser(['email' => 'boss@gmail.com']);
    googleLogin(['id_token' => googleIdToken(['email' => 'boss@gmail.com', 'sub' => 'boss'])])
        ->assertForbidden()->assertJsonPath('code', 'admin_account');
    expect(User::where('google_id', 'boss')->exists())->toBeFalse();

    owner(['email' => 'banned@gmail.com', 'suspended_at' => now()]);
    googleLogin(['id_token' => googleIdToken(['email' => 'banned@gmail.com', 'sub' => 'banned'])])
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('rejects tokens that are not valid Google ID tokens for this app', function (array $claims) {
    googleLogin(['id_token' => googleIdToken($claims), 'privacy_consent' => true])
        ->assertUnauthorized()->assertJsonPath('code', 'invalid_google_token');

    expect(User::count())->toBe(0);
})->with([
    'another app' => [['aud' => 'someone-elses-client.apps.googleusercontent.com']],
    'not Google' => [['iss' => 'https://evil.example.com']],
    'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200]],
    'unverified email' => [['email_verified' => false]],
    'no email' => [['email' => null]],
]);

it('rejects a token signed with a key Google does not publish', function () {
    googleLogin(['id_token' => googleIdToken([], 'rogue-kid'), 'privacy_consent' => true])
        ->assertUnauthorized()->assertJsonPath('code', 'invalid_google_token');
});

it('rejects garbage and refuses everything while Google sign-in is not configured', function () {
    googleLogin(['id_token' => 'not-a-jwt', 'privacy_consent' => true])->assertUnauthorized();

    config(['wasla.google.client_ids' => []]);
    googleLogin(['id_token' => googleIdToken(), 'privacy_consent' => true])->assertUnauthorized();
    googleLogin([])->assertStatus(422)->assertJsonValidationErrors('id_token');
});

it('caches Google keys and fetches a fresh set when Google rotates them', function () {
    googleLogin(['id_token' => googleIdToken(), 'privacy_consent' => true])->assertCreated();
    googleLogin(['id_token' => googleIdToken()])->assertOk();
    Http::assertSentCount(1);

    // Google now signs with a new key that our cached set does not have yet.
    googlePublishes(['test-kid', 'new-kid']);
    googleLogin(['id_token' => googleIdToken([], 'new-kid')])->assertOk();
    Http::assertSentCount(2);
    expect(Cache::get('google:jwks')['keys'])->toHaveCount(2);

    // A second unknown key within the minute does not trigger another fetch.
    googleLogin(['id_token' => googleIdToken([], 'rogue-kid')])->assertUnauthorized();
    Http::assertSentCount(2);
});

it('does not let a password-less Google account be signed in with any password', function () {
    googleLogin(['id_token' => googleIdToken(), 'privacy_consent' => true])->assertCreated();

    $this->postJson('/api/v1/auth/login', ['email' => 'ayse@gmail.com', 'password' => ''])->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => 'ayse@gmail.com', 'password' => 'anything-at-all'])
        ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
});

it('lets a Google-only owner delete the account by confirming with Google, and only with their own Google account', function () {
    $token = googleLogin(['id_token' => googleIdToken(), 'privacy_consent' => true])->json('token');
    $auth = ['Authorization' => 'Bearer '.$token];

    $this->deleteJson('/api/v1/account', ['password' => PW], $auth)->assertStatus(422)->assertJsonValidationErrors(['password', 'google_id_token']);
    $this->deleteJson('/api/v1/account', ['google_id_token' => googleIdToken(['sub' => 'another-person'])], $auth)
        ->assertUnauthorized()->assertJsonPath('code', 'invalid_google_token');
    expect(User::where('email', 'ayse@gmail.com')->exists())->toBeTrue();

    $this->deleteJson('/api/v1/account', ['google_id_token' => googleIdToken()], $auth)->assertNoContent();
    expect(User::where('email', 'ayse@gmail.com')->exists())->toBeFalse();
});

it('keeps password confirmation for owners who have a password, even when Google is linked', function () {
    $owner = owner(['email' => 'ayse@gmail.com', 'google_id' => '1098765432100']);

    $this->deleteJson('/api/v1/account', ['google_id_token' => googleIdToken()], bearer($owner))
        ->assertStatus(422)->assertJsonValidationErrors(['password', 'google_id_token']);
    expect(User::find($owner->id))->not->toBeNull();
});

it('lets a Google-only owner add a password through the reset flow', function () {
    googleLogin(['id_token' => googleIdToken(), 'privacy_consent' => true])->assertCreated();
    $user = User::where('email', 'ayse@gmail.com')->first();

    $reset = app('auth.password.broker')->createToken($user);
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ayse@gmail.com', 'token' => $reset, 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'ayse@gmail.com', 'password' => 'a-new-password-1'])
        ->assertOk()->assertJsonPath('user.has_password', true);
});

it('verifies tokens directly with the service', function () {
    $identity = app(GoogleTokenVerifier::class)->verify(googleIdToken(['email' => ' Ayse@Gmail.com ', 'email_verified' => 'true']));

    expect($identity->email)->toBe('ayse@gmail.com')->and($identity->sub)->toBe('1098765432100');
});

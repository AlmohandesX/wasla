<?php

use App\Enums\PublicTagState;
use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\UserAdminService;
use App\Services\Tags\PublicTagStateResolver;
use App\Services\Tags\TagClaimer;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function adminUser(array $attrs = []): User
{
    return owner(array_merge(['role' => 'admin', 'email_verified_at' => now()], $attrs));
}

function adminLogin(string $email, string $password = PW)
{
    return Livewire::test(Login::class)
        ->set('data.email', $email)
        ->set('data.password', $password)
        ->call('authenticate');
}

dataset('admin urls', ['/admin', '/admin/users', '/admin/tags']);

it('forbids owners and guests on every admin URL', function (string $url) {
    $this->get($url)->assertRedirect('/admin/login');

    $this->actingAs(owner(['email_verified_at' => now()]))->get($url)->assertForbidden();
})->with('admin urls');

it('lets an active admin in and applies the admin CSP only there', function () {
    $response = $this->actingAs(adminUser())->get('/admin/users')->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->toContain("'unsafe-eval'");

    expect($this->get('/')->headers->get('Content-Security-Policy'))->not->toContain('unsafe-eval');
});

it('forbids a suspended admin', function () {
    $suspended = adminUser(['suspended_at' => now()]);

    adminLogin($suspended->email)->assertHasErrors(['data.email']);
    expect(auth()->check())->toBeFalse();

    $this->actingAs($suspended)->get('/admin')->assertForbidden();
});

it('logs an admin in through the login page and rejects owners without saying why', function () {
    $admin = adminUser();
    adminLogin($admin->email)->assertHasNoErrors();
    expect(auth()->id())->toBe($admin->id);

    auth()->logout();
    app('auth')->forgetGuards();
    $user = owner(['email' => 'owner@example.com']);
    $wrongPassword = adminLogin($admin->email, 'nope-nope-nope')->errors()->get('data.email');
    $ownerCorrect = adminLogin($user->email)->errors()->get('data.email');
    $unknown = adminLogin('ghost@example.com')->errors()->get('data.email');

    expect($ownerCorrect)->toBe($wrongPassword)->and($unknown)->toBe($wrongPassword)
        ->and(auth()->check())->toBeFalse();
});

it('locks admin login after 5 failures for 15 minutes', function () {
    $admin = adminUser();
    RateLimiter::clear('x');

    foreach (range(1, 5) as $i) {
        adminLogin($admin->email, 'wrong-password-'.$i)->assertHasErrors(['data.email']);
    }

    // Even the right password is refused during the lock, and so is a collation-style variant of the address.
    adminLogin($admin->email)->assertHasErrors(['data.email']);
    expect(auth()->check())->toBeFalse();

    $this->travel(16)->minutes();
    adminLogin($admin->email)->assertHasNoErrors();
    expect(auth()->id())->toBe($admin->id);
});

it('has no public way to register an admin', function () {
    $this->get('/admin/register')->assertNotFound();
    $this->postJson('/api/v1/auth/register', [
        'name' => 'X', 'email' => 'x@example.com', 'password' => PW, 'locale' => 'tr',
        'privacy_consent' => true, 'role' => 'admin',
    ])->assertCreated();
    expect(User::where('email', 'x@example.com')->value('role'))->toBe('owner');
});

it('creates an admin from the command', function () {
    $this->artisan('wasla:make-admin', ['email' => 'Boss@Example.com'])
        ->expectsQuestion('Password (min 12 characters)', 'a-long-password-1')
        ->assertSuccessful();

    $boss = User::where('email', 'boss@example.com')->first();
    expect($boss->role)->toBe('admin')->and($boss->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue();
});

it('suspends a user, revokes their tokens and neutralizes their tags', function () {
    $admin = adminUser();
    $user = owner(['email_verified_at' => now()]);
    $user->createToken('t');
    $tag = tagFixture();
    app(TagClaimer::class)->claim($user, $tag->token, carAttrs());
    expect(app(PublicTagStateResolver::class)->resolve($tag->fresh()))->toBe(PublicTagState::Active);
    $expiry = $tag->fresh()->expires_at;

    app(UserAdminService::class)->suspend($user, $admin, 'abuse');

    expect($user->fresh()->isSuspended())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(app(PublicTagStateResolver::class)->resolve($tag->fresh()->load('car.user')))->toBe(PublicTagState::Neutral)
        ->and($tag->fresh()->expires_at->equalTo($expiry))->toBeTrue()
        ->and(AuditLog::where('action', 'user.suspend')->where('subject_id', $user->id)->exists())->toBeTrue();

    expect(fn () => app(UserAdminService::class)->suspend($user, $admin, '  '))->toThrow(InvalidArgumentException::class);
});

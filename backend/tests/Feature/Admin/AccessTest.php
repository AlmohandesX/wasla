<?php

use App\Enums\PublicTagState;
use App\Exceptions\AdminActionException;
use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\UserAdminService;
use App\Services\Tags\PublicTagStateResolver;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function adminLogin(string $username, string $password = PW)
{
    return Livewire::test(Login::class)
        ->set('data.username', $username)
        ->set('data.password', $password)
        ->call('authenticate');
}

function signOut(): void
{
    auth()->logout();
    app('auth')->forgetGuards();
}

dataset('admin urls', ['/admin', '/admin/users', '/admin/tags', '/admin/admins']);

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

    adminLogin($suspended->username)->assertHasErrors(['data.username']);
    expect(auth()->check())->toBeFalse();

    $this->actingAs($suspended)->get('/admin')->assertForbidden();
});

it('logs an admin in with username and password and rejects everyone else without saying why', function () {
    $admin = adminUser();
    adminLogin($admin->username)->assertHasNoErrors();
    expect(auth()->id())->toBe($admin->id);

    signOut();
    $owner = owner(['email' => 'owner@example.com']);
    $wrongPassword = adminLogin($admin->username, 'nope-nope-nope')->errors()->get('data.username');
    $ownerByEmail = adminLogin($owner->email)->errors()->get('data.username');
    $adminByEmail = adminLogin($admin->email)->errors()->get('data.username');
    $unknown = adminLogin('ghost')->errors()->get('data.username');

    expect($ownerByEmail)->toBe($wrongPassword)->and($adminByEmail)->toBe($wrongPassword)
        ->and($unknown)->toBe($wrongPassword)->and(auth()->check())->toBeFalse();
});

it('lets a super admin sign in to the panel', function () {
    $super = superAdminUser();

    adminLogin($super->username)->assertHasNoErrors();
    expect(auth()->id())->toBe($super->id);
});

it('shows the username field, not email, on the login page', function () {
    $this->get('/admin/login')->assertOk()->assertSee(__('admin.login.username'));
});

it('locks admin login after 5 failures for 15 minutes', function () {
    $admin = adminUser();

    foreach (range(1, 5) as $i) {
        adminLogin($admin->username, 'wrong-password-'.$i)->assertHasErrors(['data.username']);
    }

    // Even the right password is refused during the lock.
    adminLogin($admin->username)->assertHasErrors(['data.username']);
    expect(auth()->check())->toBeFalse();

    $this->travel(16)->minutes();
    adminLogin($admin->username)->assertHasNoErrors();
    expect(auth()->id())->toBe($admin->id);
});

it('keeps another admin able to log in while one admin account is locked', function () {
    $locked = adminUser();
    $other = adminUser();

    foreach (range(1, 5) as $i) {
        adminLogin($locked->username, 'wrong-password-'.$i)->assertHasErrors(['data.username']);
    }
    foreach (range(1, 5) as $i) {
        adminLogin("nobody{$i}", 'x')->assertHasErrors(['data.username']);
    }

    // Past the one-minute burst limit, but well inside the 15 minutes an IP lock would have lasted.
    $this->travel(61)->seconds();
    adminLogin($other->username)->assertHasNoErrors();
    expect(auth()->id())->toBe($other->id);
});

it('slows a burst of admin login attempts from one IP for a minute without locking other IPs', function () {
    $admin = adminUser();

    foreach (range(1, 10) as $i) {
        adminLogin("nobody{$i}", 'x')->assertHasErrors(['data.username']);
    }

    // The 11th attempt in the minute is refused even with the right password, and is not counted as a failure.
    adminLogin($admin->username)->assertHasErrors(['data.username']);
    expect(auth()->check())->toBeFalse();

    // Livewire test requests cannot change the client IP, so pin that the counter is keyed by IP (not account).
    expect(RateLimiter::attempts('admin-login-ip:127.0.0.1'))->toBe(10)
        ->and(RateLimiter::attempts('admin-login-ip:10.9.9.9'))->toBe(0);

    $this->travel(61)->seconds();
    adminLogin($admin->username)->assertHasNoErrors();
});

it('counts username spelling variants against the same account lock', function () {
    $admin = adminUser(['username' => 'boss']);

    // A sharp-s variant equals the name under the column collation: it never logs in (even with the right
    // password) and its failure counts against the real account.
    adminLogin("bo\u{DF}", PW)->assertHasErrors(['data.username']);
    expect(auth()->check())->toBeFalse();

    // Case and spacing variants are the same name: 1 + 2 + 2 = 5 failures lock it.
    foreach (range(1, 2) as $i) {
        adminLogin(' BOSS ', 'wrong-password-'.$i)->assertHasErrors(['data.username']);
    }
    foreach (range(1, 2) as $i) {
        adminLogin('boss', 'wrong-password-'.$i)->assertHasErrors(['data.username']);
    }

    adminLogin('boss')->assertHasErrors(['data.username']);
    expect(auth()->check())->toBeFalse();
});

it('logs an admin in with a differently cased username', function () {
    $admin = adminUser(['username' => 'boss']);

    adminLogin('Boss')->assertHasNoErrors();
    expect(auth()->id())->toBe($admin->id);
});

it('has no public way to register an admin', function () {
    $this->get('/admin/register')->assertNotFound();
    $this->postJson('/api/v1/auth/register', [
        'name' => 'X', 'email' => 'x@example.com', 'password' => PW, 'locale' => 'tr',
        'privacy_consent' => true, 'role' => 'admin', 'username' => 'sneaky',
    ])->assertCreated();
    $user = User::where('email', 'x@example.com')->first();
    expect($user->role)->toBe('owner')->and($user->username)->toBeNull();
});

it('creates a panel admin from the command, with a username and no email', function () {
    $this->artisan('wasla:make-admin', ['username' => 'Ops.Team'])
        ->expectsQuestion('Password (min 12 characters)', 'a-long-password-1')
        ->assertSuccessful();

    $ops = User::where('username', 'ops.team')->first();
    expect($ops->role)->toBe('admin')->and($ops->email)->toBeNull()
        ->and($ops->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();

    adminLogin('ops.team', 'a-long-password-1')->assertHasNoErrors();
});

it('makes a super admin from the command with --super and resets an existing one', function () {
    $this->artisan('wasla:make-admin', ['username' => 'root', '--super' => true, '--name' => 'Youssef'])
        ->expectsQuestion('Password (min 12 characters)', 'a-long-password-1')->assertSuccessful();
    User::where('username', 'root')->first()->forceFill(['suspended_at' => now()])->save();

    $this->artisan('wasla:make-admin', ['username' => 'root', '--super' => true])
        ->expectsQuestion('Password (min 12 characters)', 'another-password-2')
        ->expectsOutputToContain('suspended')->assertSuccessful();

    $root = User::where('username', 'root')->first();
    expect($root->role)->toBe('super_admin')->and($root->name)->toBe('Youssef')
        ->and($root->isSuspended())->toBeFalse()->and(User::where('username', 'root')->count())->toBe(1);
    adminLogin('root', 'another-password-2')->assertHasNoErrors();
});

it('refuses bad usernames, short passwords and owner accounts in the command', function () {
    owner(['username' => 'taken-owner']);

    $this->artisan('wasla:make-admin', ['username' => 'no spaces'])->assertFailed();
    $this->artisan('wasla:make-admin', ['username' => 'taken-owner'])->assertFailed();
    $this->artisan('wasla:make-admin', ['username' => 'shorty'])
        ->expectsQuestion('Password (min 12 characters)', 'short')->assertFailed();

    expect(User::where('username', 'shorty')->exists())->toBeFalse()
        ->and(User::where('username', 'taken-owner')->value('role'))->toBe('owner');
});

it('suspends a user, revokes their tokens and neutralizes their tags', function () {
    $admin = adminUser();
    [$tag, , $user] = adminClaimedTag();
    $user->createToken('t');
    expect(app(PublicTagStateResolver::class)->resolve($tag->fresh()))->toBe(PublicTagState::Active);
    $expiry = $tag->fresh()->expires_at;

    app(UserAdminService::class)->suspend($user, $admin, 'abuse');

    expect($user->fresh()->isSuspended())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(app(PublicTagStateResolver::class)->resolve($tag->fresh()->load('car.user')))->toBe(PublicTagState::Neutral)
        ->and($tag->fresh()->expires_at->equalTo($expiry))->toBeTrue()
        ->and(AuditLog::where('action', 'user.suspend')->where('subject_id', $user->id)->exists())->toBeTrue();

    expect(fn () => app(UserAdminService::class)->suspend($user, $admin, '  '))->toThrow(AdminActionException::class);
});

it('refuses to suspend yourself', function () {
    $admin = adminUser();

    expect(fn () => app(UserAdminService::class)->suspend($admin, $admin, 'oops'))->toThrow(AdminActionException::class);
    expect($admin->fresh()->isSuspended())->toBeFalse()->and(AuditLog::count())->toBe(0);
});

describe('a replayed Livewire update', function () {
    function livewireReplay(string $snapshot)
    {
        return test()->withHeaders(['X-Livewire' => '1', 'Accept' => 'application/json'])
            ->postJson(Livewire::getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]]]);
    }

    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->admin = adminUser();
        $this->actingAs($this->admin);
        // The snapshot must come from a real page request: Livewire replays the middleware of the route that rendered it.
        $html = $this->get('/admin/tags')->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        $this->snapshot = collect($m[1])->map(fn ($v) => html_entity_decode($v))
            ->first(fn ($v) => str_contains($v, 'ManageTags'));
        signOut();
    });

    it('works for an active admin (control)', function () {
        $this->actingAs($this->admin);
        livewireReplay($this->snapshot)->assertOk();
    });

    it('is refused for a suspended admin, an owner and a guest', function (?callable $who, int $status) {
        if ($who) {
            $this->actingAs($who());
        }
        $response = livewireReplay($this->snapshot);

        expect($response->status())->toBe($status);
    })->with([
        'suspended admin' => [fn () => adminUser(['email' => 's@example.com', 'suspended_at' => now()]), 403],
        'owner' => [fn () => owner(['email' => 'o@example.com', 'email_verified_at' => now()]), 403],
        'guest' => [null, 401],
    ]);
});

<?php

use App\Enums\PublicTagState;
use App\Exceptions\AdminActionException;
use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\UserAdminService;
use App\Services\Tags\PublicTagStateResolver;
use Filament\Facades\Filament;
use Livewire\Livewire;

function adminLogin(string $email, string $password = PW)
{
    return Livewire::test(Login::class)
        ->set('data.email', $email)
        ->set('data.password', $password)
        ->call('authenticate');
}

function signOut(): void
{
    auth()->logout();
    app('auth')->forgetGuards();
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

    signOut();
    $user = owner(['email' => 'owner@example.com']);
    $wrongPassword = adminLogin($admin->email, 'nope-nope-nope')->errors()->get('data.email');
    $ownerCorrect = adminLogin($user->email)->errors()->get('data.email');
    $unknown = adminLogin('ghost@example.com')->errors()->get('data.email');

    expect($ownerCorrect)->toBe($wrongPassword)->and($unknown)->toBe($wrongPassword)
        ->and(auth()->check())->toBeFalse();
});

it('locks admin login after 5 failures for 15 minutes', function () {
    $admin = adminUser();

    foreach (range(1, 5) as $i) {
        adminLogin($admin->email, 'wrong-password-'.$i)->assertHasErrors(['data.email']);
    }

    // Even the right password is refused during the lock.
    adminLogin($admin->email)->assertHasErrors(['data.email']);
    expect(auth()->check())->toBeFalse();

    $this->travel(16)->minutes();
    adminLogin($admin->email)->assertHasNoErrors();
    expect(auth()->id())->toBe($admin->id);
});

it('counts email spelling variants against the same account lock', function () {
    $admin = adminUser(['email' => 'boss@example.com']);

    // A zero-width variant matches the account under the column collation, but the form rejects it before any lookup.
    adminLogin("bo\u{200D}ss@example.com")->assertHasErrors();
    expect(auth()->check())->toBeFalse();

    // A case variant passes the form and shares the account counter with the exact address.
    foreach (range(1, 3) as $i) {
        adminLogin('BOSS@Example.com', 'wrong-password-'.$i)->assertHasErrors(['data.email']);
    }
    foreach (range(1, 2) as $i) {
        adminLogin($admin->email, 'wrong-password-'.$i)->assertHasErrors(['data.email']);
    }

    adminLogin('Boss@example.com')->assertHasErrors(['data.email']);
    adminLogin($admin->email)->assertHasErrors(['data.email']);
    expect(auth()->check())->toBeFalse();
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
    expect($boss->role)->toBe('admin')->and($boss->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('warns when the command reinstates a suspended account and audits a re-enabled admin', function () {
    $suspendedAdmin = adminUser(['suspended_at' => now()]);
    $suspendedOwner = owner(['suspended_at' => now()]);

    $this->artisan('wasla:make-admin', ['email' => $suspendedOwner->email])
        ->expectsOutputToContain('suspended')->assertSuccessful();
    expect($suspendedOwner->fresh()->isSuspended())->toBeFalse()->and(AuditLog::count())->toBe(0);

    $this->artisan('wasla:make-admin', ['email' => $suspendedAdmin->email])
        ->expectsOutputToContain('suspended')->assertSuccessful();
    expect(AuditLog::where('action', 'user.make_admin_command')->where('admin_id', $suspendedAdmin->id)->exists())->toBeTrue();
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

    it('is refused for a suspended admin, an owner and a guest', function (?callable $who) {
        if ($who) {
            $this->actingAs($who());
        }
        $response = livewireReplay($this->snapshot);

        expect($response->status())->not->toBe(200);
    })->with([
        'suspended admin' => [fn () => adminUser(['email' => 's@example.com', 'suspended_at' => now()])],
        'owner' => [fn () => owner(['email' => 'o@example.com', 'email_verified_at' => now()])],
        'guest' => [null],
    ]);
});

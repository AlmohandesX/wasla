<?php

use App\Exceptions\AdminActionException;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\AdminResource\Pages\ManageAdmins;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\AdminAccountService;
use App\Services\Auth\UserAdminService;
use Database\Seeders\SuperAdminSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

function createAdminAction(array $data)
{
    return Livewire::test(ManageAdmins::class)->callAction(TestAction::make('create')->table(), $data);
}

const NEW_ADMIN = ['name' => 'Elif', 'username' => 'Elif.K', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'];

it('shows the Admins page to super admins only', function () {
    $this->actingAs(superAdminUser())->get('/admin/admins')->assertOk();
});

it('hides the Admins page from plain admins', function () {
    $this->actingAs(adminUser())->get('/admin/admins')->assertForbidden();
});

it('lets a super admin create an admin with just a name, username and password', function () {
    $super = superAdminUser();
    $this->actingAs($super);

    createAdminAction(NEW_ADMIN)->assertHasNoFormErrors();

    $elif = User::where('username', 'elif.k')->first();
    expect($elif)->not->toBeNull()
        ->and($elif->role)->toBe('admin')->and($elif->email)->toBeNull()->and($elif->name)->toBe('Elif')
        ->and(AuditLog::where('action', 'admin.create')->where('admin_id', $super->id)->where('subject_id', $elif->id)->exists())->toBeTrue();

    // The new admin can sign in right away, with the username in any case.
    auth()->logout();
    Livewire::test(Login::class)->set('data.username', 'ELIF.K')->set('data.password', 'a-long-password-1')
        ->call('authenticate')->assertHasNoErrors();
    expect(auth()->id())->toBe($elif->id);
});

it('validates the new admin', function (array $override, string $field) {
    $this->actingAs(superAdminUser());
    owner(['username' => 'taken']);

    createAdminAction(array_merge(NEW_ADMIN, $override))->assertHasFormErrors([$field]);
    expect(User::where('name', 'Elif')->exists())->toBeFalse();
})->with([
    'username with spaces' => [['username' => 'elif k'], 'username'],
    'username too short' => [['username' => 'ek'], 'username'],
    'username taken (any case)' => [['username' => 'TAKEN'], 'username'],
    'password too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'passwords differ' => [['password_confirmation' => 'something-else-1'], 'password'],
    'no name' => [['name' => ''], 'name'],
]);

it('lists only panel accounts on the Admins page', function () {
    $this->actingAs($super = superAdminUser());
    $admin = adminUser();
    $owner = owner();

    Livewire::test(ManageAdmins::class)->assertCanSeeTableRecords([$super, $admin])->assertCanNotSeeTableRecords([$owner]);
});

it('refuses to create admins for anyone but an active super admin', function () {
    $service = app(AdminAccountService::class);

    expect(fn () => $service->create(adminUser(), 'X', 'xxx', 'a-long-password-1'))->toThrow(AdminActionException::class)
        ->and(fn () => $service->create(superAdminUser(['suspended_at' => now()]), 'X', 'yyy', 'a-long-password-1'))->toThrow(AdminActionException::class);
    expect(User::whereIn('username', ['xxx', 'yyy'])->exists())->toBeFalse();
});

it('keeps admin accounts out of a plain admin\'s reach and lets a super admin suspend them', function () {
    $admin = adminUser();
    $other = adminUser();
    $super = superAdminUser();
    $service = app(UserAdminService::class);

    expect(fn () => $service->suspend($super, $admin, 'coup'))->toThrow(AdminActionException::class)
        ->and(fn () => $service->suspend($other, $admin, 'coup'))->toThrow(AdminActionException::class);
    expect($super->fresh()->isSuspended())->toBeFalse()->and($other->fresh()->isSuspended())->toBeFalse();

    $service->suspend($other, $super, 'left the team');
    expect($other->fresh()->isSuspended())->toBeTrue();
    expect(fn () => $service->unsuspend($other, $admin, 'back'))->toThrow(AdminActionException::class);
});

it('seeds the super admin from configuration, and updates the same account when run again', function () {
    config(['wasla.super_admin' => ['name' => 'Youssef', 'username' => 'Youssef', 'password' => 'a-long-password-1']]);
    $this->seed(SuperAdminSeeder::class);

    $root = User::where('username', 'youssef')->first();
    expect($root->role)->toBe('super_admin')->and($root->name)->toBe('Youssef')->and($root->email)->toBeNull();

    config(['wasla.super_admin.password' => 'another-password-2']);
    $this->seed(SuperAdminSeeder::class);
    expect(User::where('username', 'youssef')->count())->toBe(1);

    Livewire::test(Login::class)->set('data.username', 'youssef')->set('data.password', 'another-password-2')
        ->call('authenticate')->assertHasNoErrors();
});

it('refuses to seed without a valid username and a long enough password', function (array $config) {
    config(['wasla.super_admin' => $config + ['name' => 'Youssef']]);

    expect(fn () => $this->seed(SuperAdminSeeder::class))->toThrow(RuntimeException::class);
    expect(User::whereIn('role', User::ADMIN_ROLES)->exists())->toBeFalse();
})->with([
    'no username' => [['username' => null, 'password' => 'a-long-password-1']],
    'bad username' => [['username' => 'you ssef', 'password' => 'a-long-password-1']],
    'short password' => [['username' => 'youssef', 'password' => 'short']],
]);

it('refuses panel accounts in the mobile app, both at login and with an existing token', function () {
    $admin = adminUser(['email' => 'boss@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => PW])
        ->assertForbidden()->assertJsonPath('code', 'admin_account');
    expect($admin->tokens()->count())->toBe(0);

    $auth = bearer($admin);
    $this->getJson('/api/v1/me', $auth)->assertForbidden()->assertJsonPath('code', 'admin_account');
    $this->getJson('/api/v1/cars', $auth)->assertForbidden()->assertJsonPath('code', 'admin_account');
});

it('refuses a super admin token in the mobile app but not an owner token', function () {
    $this->getJson('/api/v1/me', bearer(superAdminUser()))->assertForbidden()->assertJsonPath('code', 'admin_account');
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/me', bearer(owner()))->assertOk();
});

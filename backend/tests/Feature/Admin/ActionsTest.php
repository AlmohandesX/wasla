<?php

use App\Filament\Resources\TagResource\Pages\ManageTags;
use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Models\AuditLog;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

function tagAction(string $name, $tag): TestAction
{
    return TestAction::make($name)->table($tag);
}

it('stores a set expiry entered in Istanbul time as the same instant in UTC', function () {
    [$tag] = adminClaimedTag();

    Livewire::test(ManageTags::class)
        ->callAction(tagAction('extend', $tag), ['expires_at' => '2030-06-01 12:00:00', 'reason' => 'goodwill'])
        ->assertHasNoErrors();

    expect($tag->fresh()->expires_at->utc()->format('Y-m-d H:i:s'))->toBe('2030-06-01 09:00:00')
        ->and(AuditLog::where('action', 'tag.extend')->first()->meta['to'])->toContain('2030-06-01T09:00:00');
});

it('revokes a tag from the table and notifies', function () {
    [$tag] = adminClaimedTag();

    Livewire::test(ManageTags::class)
        ->callAction(tagAction('revoke', $tag), ['reason' => 'stolen'])
        ->assertNotified(__('admin.tags.revoked'));

    expect($tag->fresh()->isRevoked())->toBeTrue()
        ->and(AuditLog::where('action', 'tag.revoke')->value('admin_id'))->toBe($this->admin->id);
});

it('force releases a tag from the table', function () {
    [$tag] = adminClaimedTag();

    Livewire::test(ManageTags::class)
        ->callAction(tagAction('forceRelease', $tag), ['reason' => 'sold'])
        ->assertNotified(__('admin.tags.released'));

    expect($tag->fresh()->car_id)->toBeNull();
});

it('replaces a tag from the table', function () {
    [$old, $car] = adminClaimedTag();
    $new = tagFixture();

    Livewire::test(ManageTags::class)
        ->callAction(tagAction('replace', $old), ['new_tag_id' => $new->id, 'reason' => 'damaged'])
        ->assertNotified(__('admin.tags.replaced'));

    expect($new->fresh()->car_id)->toBe($car->id)->and($old->fresh()->isRevoked())->toBeTrue();
});

it('shows the refusal instead of crashing when the replacement tag is not fresh', function () {
    [$old] = adminClaimedTag();
    [$taken] = adminClaimedTag();

    Livewire::test(ManageTags::class)
        ->callAction(tagAction('replace', $old), ['new_tag_id' => $taken->id, 'reason' => 'damaged'])
        ->assertNotified(__('admin.invalid_replacement'));

    expect($old->fresh()->isRevoked())->toBeFalse();
});

it('requires a reason on every tag action', function (string $action) {
    [$tag] = adminClaimedTag();
    $data = ['reason' => '', 'new_tag_id' => tagFixture()->id, 'expires_at' => '2030-06-01 12:00:00'];

    Livewire::test(ManageTags::class)
        ->callAction(tagAction($action, $tag), $data)
        ->assertHasActionErrors(['reason' => 'required']);

    expect(AuditLog::count())->toBe(0)->and($tag->fresh()->isRevoked())->toBeFalse();
})->with(['replace', 'forceRelease', 'extend', 'revoke']);

it('suspends and reinstates a user from the table', function () {
    $user = owner(['email_verified_at' => now()]);

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('suspend')->table($user), ['reason' => 'abuse'])
        ->assertNotified(__('admin.users.suspended'));
    expect($user->fresh()->isSuspended())->toBeTrue();

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('unsuspend')->table($user), ['reason' => 'appeal'])
        ->assertNotified(__('admin.users.unsuspended'));
    expect($user->fresh()->isSuspended())->toBeFalse()
        ->and(AuditLog::pluck('action')->all())->toBe(['user.suspend', 'user.unsuspend']);
});

it('requires a reason to suspend a user', function () {
    $user = owner();

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('suspend')->table($user), ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($user->fresh()->isSuspended())->toBeFalse();
});

it('does not offer suspending yourself', function () {
    Livewire::test(ManageUsers::class)
        ->assertActionHidden(TestAction::make('suspend')->table($this->admin));
});

it('serves translated admin strings and Filament own Turkish strings', function () {
    foreach (['tr', 'en'] as $locale) {
        $keys = array_keys(Arr::dot(trans('admin', [], $locale)));
        expect($keys)->not->toBeEmpty();
        foreach ($keys as $key) {
            expect(trans('admin.'.$key, [], $locale))->not->toBe('admin.'.$key);
        }
    }
    expect(array_keys(Arr::dot(trans('admin', [], 'tr'))))->toBe(array_keys(Arr::dot(trans('admin', [], 'en'))))
        ->and(app()->getLocale())->toBe('tr')
        ->and(trans('filament-panels::auth/pages/login.title', [], 'tr'))->not->toBe('filament-panels::auth/pages/login.title');
});

it('loads the login page without third-party hosts and with its assets present', function () {
    $this->app['auth']->forgetGuards();
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->not->toContain('fonts.bunny.net')->not->toContain('ui-avatars.com');

    preg_match_all('#(?:href|src)="([^"]+\.(?:css|js))(?:\?[^"]*)?"#', $html, $m);
    $local = collect($m[1])->map(fn ($u) => parse_url($u, PHP_URL_PATH))->filter(fn ($p) => str_starts_with($p, '/css/filament') || str_starts_with($p, '/js/filament'));
    expect($local)->not->toBeEmpty();
    foreach ($local as $path) {
        expect(public_path(ltrim($path, '/')))->toBeFile();
    }
});

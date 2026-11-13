<?php

use App\Exceptions\AdminActionException;
use App\Exceptions\InvalidReplacementTag;
use App\Exceptions\TagRevoked;
use App\Models\AuditLog;
use App\Services\Tags\SubscriptionService;
use App\Services\Tags\TagAdminService;
use App\Services\Tags\TagClaimer;
use Illuminate\Support\Carbon;

it('moves the exact expiry and car to the new tag on replace', function () {
    [$old, $car] = adminClaimedTag();
    $old->forceFill(['expires_at' => Carbon::parse('2027-03-04 10:11:12', 'UTC')])->save();
    $new = tagFixture();

    app(TagAdminService::class)->replace($old, $new, adminUser(), 'damaged sticker');

    $new->refresh();
    expect($new->car_id)->toBe($car->id)
        ->and($new->expires_at->equalTo(Carbon::parse('2027-03-04 10:11:12', 'UTC')))->toBeTrue()
        ->and($new->first_activated_at->equalTo($old->fresh()->first_activated_at))->toBeTrue()
        ->and($new->sold_at)->not->toBeNull()
        ->and($old->fresh()->car_id)->toBeNull()
        ->and($old->fresh()->replaced_by_tag_id)->toBe($new->id);

    // The subscription can still be renewed on the replacement.
    expect(fn () => app(SubscriptionService::class)->extendOneYear($new))->not->toThrow(LogicException::class);
});

it('permanently revokes the old tag on replace', function () {
    [$old] = adminClaimedTag();
    app(TagAdminService::class)->replace($old, tagFixture(), adminUser(), 'lost');

    expect($old->fresh()->isRevoked())->toBeTrue();
    expect(fn () => app(TagClaimer::class)->claim(owner(['email' => 'b@example.com']), $old->token, carAttrs()))
        ->toThrow(TagRevoked::class);
});

it('refuses a replacement tag that was already activated, linked or revoked', function (array $state) {
    [$old] = adminClaimedTag();
    $new = tagFixture($state);
    $before = $old->fresh()->getAttributes();

    expect(fn () => app(TagAdminService::class)->replace($old, $new, adminUser(), 'why'))
        ->toThrow(InvalidReplacementTag::class);

    expect($old->fresh()->getAttributes())->toBe($before)->and(AuditLog::count())->toBe(0);
})->with([
    'activated' => [['first_activated_at' => now()]],
    'revoked' => [['revoked_at' => now()]],
    'with expiry' => [['expires_at' => now()->addYear()]],
]);

it('refuses a replacement tag that is linked to a car', function () {
    [$old] = adminClaimedTag();
    [$other] = adminClaimedTag();

    expect(fn () => app(TagAdminService::class)->replace($old, $other, adminUser(), 'why'))
        ->toThrow(InvalidReplacementTag::class);
    expect($old->fresh()->isRevoked())->toBeFalse();
});

it('keeps expiry on force release and leaves the tag claimable', function () {
    [$tag] = adminClaimedTag();
    $expiry = $tag->expires_at;

    app(TagAdminService::class)->forceRelease($tag, adminUser(), 'sold car');

    expect($tag->fresh()->car_id)->toBeNull()
        ->and($tag->fresh()->expires_at->equalTo($expiry))->toBeTrue();
    $buyer = owner(['email' => 'buyer@example.com']);
    expect(app(TagClaimer::class)->claim($buyer, $tag->token, carAttrs())->user_id)->toBe($buyer->id);
});

it('revokes and extends manually', function () {
    [$tag] = adminClaimedTag();
    $admin = adminUser();
    $to = now()->addYears(3)->startOfSecond();

    app(TagAdminService::class)->extendManually($tag, $to, $admin, 'goodwill');
    expect($tag->fresh()->expires_at->equalTo($to))->toBeTrue();

    app(TagAdminService::class)->revoke($tag, $admin, 'stolen');
    expect($tag->fresh()->isRevoked())->toBeTrue();

    expect(AuditLog::pluck('action')->all())->toBe(['tag.extend', 'tag.revoke']);
});

it('refuses to set the expiry of a revoked or never-activated tag, or to a past date', function () {
    $admin = adminUser();
    $service = app(TagAdminService::class);
    [$tag] = adminClaimedTag();
    $before = $tag->expires_at;

    expect(fn () => $service->extendManually($tag, now()->subDay(), $admin, 'why'))->toThrow(AdminActionException::class);
    expect(fn () => $service->extendManually(tagFixture(), now()->addYear(), $admin, 'why'))->toThrow(AdminActionException::class);

    $service->revoke($tag, $admin, 'stolen');
    expect(fn () => $service->extendManually($tag, now()->addYear(), $admin, 'why'))->toThrow(AdminActionException::class);

    expect($tag->fresh()->expires_at->equalTo($before))->toBeTrue()
        ->and(AuditLog::pluck('action')->all())->toBe(['tag.revoke']);
});

it('requires a reason and writes an audit record for each action', function () {
    $admin = adminUser();
    $service = app(TagAdminService::class);
    [$a] = adminClaimedTag();
    [$b] = adminClaimedTag();
    [$c] = adminClaimedTag();
    [$d] = adminClaimedTag();

    foreach ([
        fn ($r) => $service->replace($a, tagFixture(), $admin, $r),
        fn ($r) => $service->revoke($b, $admin, $r),
        fn ($r) => $service->forceRelease($c, $admin, $r),
        fn ($r) => $service->extendManually($d, now()->addYears(2), $admin, $r),
    ] as $run) {
        expect(fn () => $run('   '))->toThrow(AdminActionException::class);
    }
    expect(AuditLog::count())->toBe(0)->and($b->fresh()->isRevoked())->toBeFalse();

    $service->replace($a, tagFixture(), $admin, 'r1');
    $service->revoke($b, $admin, 'r2');
    $service->forceRelease($c, $admin, 'r3');
    $service->extendManually($d, now()->addYears(2), $admin, 'r4');

    $logs = AuditLog::orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['tag.replace', 'tag.revoke', 'tag.force_release', 'tag.extend'])
        ->and($logs->pluck('meta.reason')->all())->toBe(['r1', 'r2', 'r3', 'r4'])
        ->and($logs->pluck('admin_id')->unique()->all())->toBe([$admin->id])
        ->and($logs->every(fn ($l) => $l->subject_type === 'tag'))->toBeTrue();
});

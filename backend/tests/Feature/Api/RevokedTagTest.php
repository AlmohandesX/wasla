<?php

use App\Models\AuditLog;
use App\Services\Tags\TagAdminService;

it('shows a car whose sticker was revoked as unlinked and lets the owner link a new sticker', function () {
    [$old, $car, $user] = adminClaimedTag();
    $headers = bearer($user);

    app(TagAdminService::class)->revoke($old, adminUser(), 'reported stolen');

    expect($old->fresh()->car_id)->toBeNull()->and($old->fresh()->isRevoked())->toBeTrue();
    expect(AuditLog::where('action', 'tag.revoke')->sole()->meta['car_id'])->toBe($car->id);

    $this->getJson("/api/v1/cars/{$car->id}", $headers)->assertOk()
        ->assertJsonPath('linked', false)
        ->assertJsonPath('subscription.active', false);

    // The visitor sees the neutral page, not the claim page.
    $this->get("/t/{$old->token}")->assertOk()->assertDontSee('/chat');
    $this->get("/t/{$old->token}")->assertSee(__('visitor.neutral_body'));

    // Renewal has nothing to renew.
    $this->postJson("/api/v1/cars/{$car->id}/renewals", [
        'billing_name' => 'Ayse Yilmaz', 'billing_address' => 'Moda Cad. No 5', 'billing_city' => 'Istanbul',
        'billing_national_id' => GATEWAY_TEST_TCKN, 'billing_phone' => '05550000001', 'legal_accepted' => true,
    ], $headers)->assertStatus(422)->assertJsonPath('code', 'renewal_no_tag');

    // The revoked sticker cannot be claimed again, by anyone.
    $this->postJson('/api/v1/tags/claim', ['token' => $old->token, 'car_id' => $car->id], $headers)
        ->assertJsonPath('code', 'tag_revoked');

    // A new sticker links to the same car.
    $fresh = tagFixture();
    $this->postJson('/api/v1/tags/claim', ['token' => $fresh->token, 'car_id' => $car->id], $headers)
        ->assertOk()->assertJsonPath('id', $car->id)->assertJsonPath('linked', true)
        ->assertJsonPath('subscription.active', true);
});

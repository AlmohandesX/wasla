<?php

use App\Enums\PaymentStatus;
use App\Events\ChatMessageCreated;
use App\Listeners\NotifyOwnerOfVisitorMessage;
use App\Models\AuditLog;
use App\Models\Car;
use App\Models\ChatMessage;
use App\Models\DeviceToken;
use App\Models\EmergencyContact;
use App\Models\ModerationEvent;
use App\Models\Payment;
use App\Models\Reminder;
use App\Models\SentNotification;
use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\Payments\Drivers\FakeGateway;
use App\Services\Payments\RenewalService;
use App\Services\Tags\TagClaimer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('photos');
    Storage::fake('s3');
});

function requestAccountDeletion(User $user, mixed $password = PW)
{
    return test()->deleteJson('/api/v1/account', ['password' => $password], bearer($user));
}

function accountOwner(array $attrs = []): User
{
    return owner(['email_verified_at' => now()] + $attrs);
}

it('requires the current password', function () {
    $user = accountOwner();
    $car = linkedCar($user);

    requestAccountDeletion($user, 'wrong-password-1')->assertStatus(422)->assertJsonPath('code', 'invalid_password');
    requestAccountDeletion($user, ['x'])->assertStatus(422);
    requestAccountDeletion($user, 12345678)->assertStatus(422);

    expect(User::find($user->id))->not->toBeNull()->and(Car::find($car->id))->not->toBeNull();
});

it('rate limits wrong password attempts', function () {
    $user = accountOwner();

    foreach (range(1, 3) as $i) {
        requestAccountDeletion($user, 'wrong-password-'.$i)->assertStatus(422);
    }
    requestAccountDeletion($user, 'wrong-password-4')->assertStatus(429);
    // Even the right password is held back while limited.
    requestAccountDeletion($user)->assertStatus(429);
    expect(User::find($user->id))->not->toBeNull();
});

it('makes tags claimable with expiry dates kept', function () {
    $user = accountOwner();
    $expires = now()->addMonths(3)->startOfSecond();
    $car = linkedCar($user, $expires);
    $tag = $car->tag;
    $tag->update(['sold_at' => now()->subDays(5)]);
    $activated = $tag->fresh()->first_activated_at;

    requestAccountDeletion($user)->assertNoContent();

    $tag->refresh();
    expect($tag->car_id)->toBeNull()
        ->and($tag->expires_at->equalTo($expires))->toBeTrue()
        ->and($tag->first_activated_at->equalTo($activated))->toBeTrue()
        ->and($tag->sold_at)->not->toBeNull();
});

it('erases cars, contacts, reminders, chats, photos, device tokens and API tokens', function () {
    $user = accountOwner();
    $car = linkedCar($user);
    Storage::disk('photos')->put('car-photos/a.jpg', 'x');
    Storage::disk('s3')->put('car-photos/b.jpg', 'x');
    $car->update(['photo_disk' => 'photos', 'photo_path' => 'car-photos/a.jpg']);
    Car::create(carAttrs(['plate' => '06 XYZ 999']) + ['user_id' => $user->id, 'photo_disk' => 's3', 'photo_path' => 'car-photos/b.jpg']);
    EmergencyContact::create(['car_id' => $car->id, 'label' => 'Anne', 'phone' => '05550000001']);
    Reminder::create(['car_id' => $car->id, 'type' => 'oil_change', 'due_on' => now()->addDays(10)->toDateString()]);
    $thread = chatThread($car, 'secret visitor words');
    $messageId = $thread->messages()->first()->id;
    DeviceToken::create(['user_id' => $user->id, 'token' => 'ExponentPushToken[abc]', 'platform' => 'ios']);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'h', 'created_at' => now()]);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $other = accountOwner();
    $otherCar = linkedCar($other);
    EmergencyContact::create(['car_id' => $otherCar->id, 'label' => 'Baba', 'phone' => '05550000002']);
    DeviceToken::create(['user_id' => $other->id, 'token' => 'ExponentPushToken[zzz]']);
    $headers = bearer($user);

    $this->deleteJson('/api/v1/account', ['password' => PW], $headers)->assertNoContent();

    expect(User::find($user->id))->toBeNull()
        ->and(Car::where('user_id', $user->id)->count())->toBe(0)
        ->and(EmergencyContact::count())->toBe(1)
        ->and(Reminder::count())->toBe(0)
        ->and(DB::table('chat_threads')->where('car_id', $car->id)->count())->toBe(0)
        ->and(ChatMessage::find($messageId))->toBeNull()
        ->and(DeviceToken::where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(Car::find($otherCar->id))->not->toBeNull()
        ->and(DeviceToken::where('user_id', $other->id)->count())->toBe(1);
    Storage::disk('photos')->assertMissing('car-photos/a.jpg');
    Storage::disk('s3')->assertMissing('car-photos/b.jpg');
    // The token is gone, so the same bearer no longer works.
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
});

it('erases moderation events holding the owner text or their thread ids', function () {
    $user = accountOwner();
    $car = linkedCar($user);
    $thread = chatThread($car);
    $mine = ModerationEvent::create(['kind' => 'blocked', 'surface' => 'status', 'text' => 'owner text', 'source' => 'blacklist', 'user_id' => $user->id]);
    $inThread = ModerationEvent::create(['kind' => 'flagged', 'surface' => 'chat', 'text' => 'visitor text', 'source' => 'provider', 'thread_id' => $thread->id]);
    $otherUser = accountOwner();
    $kept = ModerationEvent::create(['kind' => 'blocked', 'surface' => 'chat', 'text' => 'unrelated', 'source' => 'blacklist', 'thread_id' => chatThread(linkedCar($otherUser))->id, 'user_id' => $otherUser->id]);
    $reminder = Reminder::create(['car_id' => $car->id, 'type' => 'oil_change', 'due_on' => now()->addDay()->toDateString()]);
    $ledger = SentNotification::create(['kind' => 'reminder', 'subject_id' => $reminder->id, 'marker' => 'd1:x']);

    requestAccountDeletion($user)->assertNoContent();

    expect(ModerationEvent::find($mine->id))->toBeNull()
        ->and(ModerationEvent::find($inThread->id))->toBeNull()
        ->and(ModerationEvent::find($kept->id))->not->toBeNull()
        ->and(SentNotification::find($ledger->id))->toBeNull();
});

it('keeps payment records and clears the user link', function () {
    $user = accountOwner();
    $tag = linkedCar($user)->tag;
    $payment = Payment::create([
        'tag_id' => $tag->id, 'extended_tag_id' => $tag->id, 'user_id' => $user->id, 'provider' => 'fake', 'reference' => 'WKEEP0001',
        'amount_kurus' => 79900, 'currency' => 'TRY', 'status' => PaymentStatus::Paid, 'paid_at' => now(),
        'billing_name' => 'Ayse Yilmaz', 'billing_address' => 'Moda Cad. 5', 'billing_city' => 'Istanbul', 'billing_national_id' => '10000000146',
        'legal_accepted_at' => now(), 'legal_text_version' => '1',
    ]);
    $audit = AuditLog::create(['admin_id' => User::factory()->create(['role' => 'admin'])->id, 'action' => 'user.suspend', 'subject_type' => 'user', 'subject_id' => $user->id]);

    requestAccountDeletion($user)->assertNoContent();

    $payment->refresh();
    expect($payment->user_id)->toBeNull()
        ->and($payment->amount_kurus)->toBe(79900)
        ->and($payment->billing_name)->toBe('Ayse Yilmaz')
        ->and($payment->billing_national_id)->toBe('10000000146')
        ->and($payment->tag_id)->toBe($tag->id)
        ->and(AuditLog::query()->with('admin')->find($audit->id)->admin)->not->toBeNull();
});

it('settles a late callback and refunds a payment of a deleted user', function () {
    $user = accountOwner();
    $tag = linkedCar($user, now()->addMonth())->tag;
    $expires = $tag->expires_at;
    $payment = Payment::create([
        'tag_id' => $tag->id, 'extended_tag_id' => $tag->id, 'user_id' => $user->id, 'provider' => 'fake', 'reference' => 'WLATE0001',
        'amount_kurus' => 79900, 'currency' => 'TRY', 'status' => PaymentStatus::Pending,
        'legal_accepted_at' => now(), 'legal_text_version' => '1',
    ]);
    requestAccountDeletion($user)->assertNoContent();

    FakeGateway::setOutcome('WLATE0001', PaymentStatus::Paid, 79900);
    $this->post('/payments/callback/fake', ['reference' => 'WLATE0001', 'signature' => FakeGateway::sign('WLATE0001')])->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->user_id)->toBeNull()
        ->and($tag->fresh()->expires_at->toDateTimeString())->toBe($expires->copy()->addYear()->toDateTimeString());

    $admin = User::factory()->create(['role' => 'admin']);
    app(RenewalService::class)->refund($payment, $admin, 'user request');
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($tag->fresh()->expires_at->toDateTimeString())->toBe($expires->toDateTimeString());
});

it('frees the email for a new registration', function () {
    $user = accountOwner(['email' => 'ayse@example.com']);

    requestAccountDeletion($user)->assertNoContent();

    register(['email' => 'ayse@example.com'])->assertCreated();
});

it('does not reset expiry when a deleted account tag is reclaimed', function () {
    $user = accountOwner();
    $expires = now()->addMonths(2)->startOfSecond();
    $tag = linkedCar($user, $expires)->tag;
    requestAccountDeletion($user)->assertNoContent();

    $newOwner = accountOwner();
    app(TagClaimer::class)->claim($newOwner, $tag->token, carAttrs());

    $tag->refresh();
    expect($tag->car_id)->not->toBeNull()->and($tag->expires_at->equalTo($expires))->toBeTrue();
});

it('refuses to delete an admin through this route', function () {
    $admin = User::factory()->create(['role' => 'admin', 'password' => PW, 'email_verified_at' => now()]);

    requestAccountDeletion($admin)->assertForbidden()->assertJsonPath('code', 'admin_account_protected');

    expect(User::find($admin->id))->not->toBeNull();
});

it('refuses a suspended owner and leaves support to handle it', function () {
    $user = accountOwner(['suspended_at' => now()]);

    requestAccountDeletion($user)->assertForbidden()->assertJsonPath('code', 'account_suspended');

    expect(User::find($user->id))->not->toBeNull();
});

it('lets an owner with an expired tag or unverified email delete the account', function () {
    $expired = accountOwner();
    linkedCar($expired, now()->subDay());
    $unverified = owner(['email_verified_at' => null]);

    requestAccountDeletion($expired)->assertNoContent();
    $this->app['auth']->forgetGuards();
    requestAccountDeletion($unverified)->assertNoContent();
});

it('keeps the erasure when a photo cannot be deleted and logs no plate or path', function () {
    $user = accountOwner();
    $car = linkedCar($user, null, ['plate' => '34 PLT 777']);
    $car->update(['photo_disk' => 'photos', 'photo_path' => 'car-photos/secret.jpg']);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('boom car-photos/secret.jpg'));
    Storage::shouldReceive('disk')->with('photos')->andReturn($disk);
    Log::spy();

    app(AccountEraser::class)->erase($user);

    expect(User::find($user->id))->toBeNull()->and(Car::find($car->id))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
    assertNotLogged('34 PLT 777', 'secret.jpg', $user->email, $user->name);
});

it('deletes files only after the transaction commits', function () {
    $user = accountOwner();
    $car = linkedCar($user);
    Storage::disk('photos')->put('car-photos/a.jpg', 'x');
    $car->update(['photo_disk' => 'photos', 'photo_path' => 'car-photos/a.jpg']);
    // A failing database step must leave the file alone.
    User::deleting(fn () => throw new RuntimeException('db failure'));

    expect(fn () => app(AccountEraser::class)->erase($user))->toThrow(RuntimeException::class);

    Storage::disk('photos')->assertExists('car-photos/a.jpg');
    expect(Car::find($car->id))->not->toBeNull();
});

it('logs a path-free warning when a disk reports a failed delete by returning false', function () {
    $user = accountOwner();
    $car = linkedCar($user, null, ['plate' => '34 PLT 777']);
    $car->update(['photo_disk' => 'photos', 'photo_path' => 'car-photos/secret.jpg']);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andReturn(false);
    Storage::shouldReceive('disk')->with('photos')->andReturn($disk);
    Log::spy();

    app(AccountEraser::class)->erase($user);

    expect(User::find($user->id))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
    assertNotLogged('34 PLT 777', 'secret.jpg', $user->email);
});

it('limits deletion attempts to 5 an hour and counts per user', function () {
    $user = accountOwner();
    $other = accountOwner();

    foreach (range(1, 5) as $i) {
        $this->travel($i * 2)->minutes();
        requestAccountDeletion($user, 'wrong-password-'.$i)->assertStatus(422);
    }
    $this->travel(12)->minutes();
    requestAccountDeletion($user, 'wrong-password-6')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    // Another user is unaffected (fresh guard: the test app caches the first user).
    $this->app['auth']->forgetGuards();
    requestAccountDeletion($other, 'wrong-password-1')->assertStatus(422);
});

it('scrubs a pending payment of an erased user without a user link', function () {
    $tag = tagFixture();
    $payment = Payment::create([
        'tag_id' => $tag->id, 'user_id' => null, 'provider' => 'fake', 'reference' => 'WORPHAN01',
        'amount_kurus' => 79900, 'currency' => 'TRY', 'status' => PaymentStatus::Pending,
        'billing_name' => 'Ayse', 'billing_national_id' => '10000000146',
        'legal_accepted_at' => now(), 'legal_text_version' => '1',
    ]);
    DB::table('payments')->where('id', $payment->id)->update(['created_at' => now()->subDays(8)]);

    $this->artisan('wasla:scrub-billing')->assertSuccessful();

    expect($payment->fresh()->billing_national_id)->toBeNull();
});

it('erases an account from the support command, even a suspended one', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = accountOwner(['suspended_at' => now(), 'email' => 'gone@example.com']);
    linkedCar($user);

    $this->artisan('wasla:erase-account', ['email' => 'gone@example.com', '--admin' => $admin->email])
        ->expectsConfirmation('Erase this account permanently?', 'yes')
        ->assertSuccessful();

    expect(User::find($user->id))->toBeNull();
    $log = AuditLog::where('action', 'account.erase')->firstOrFail();
    expect($log->admin_id)->toBe($admin->id)->and($log->subject_id)->toBe($user->id)
        ->and(json_encode($log->meta))->not->toContain('gone@example.com');
});

it('does not erase when the support command is not confirmed or targets an admin', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email' => 'boss@example.com']);
    $user = accountOwner(['email' => 'stay@example.com']);

    $this->artisan('wasla:erase-account', ['email' => 'stay@example.com', '--admin' => $admin->email])
        ->expectsConfirmation('Erase this account permanently?', 'no')
        ->assertFailed();
    $this->artisan('wasla:erase-account', ['email' => 'boss@example.com', '--admin' => $admin->email])
        ->assertFailed();

    expect(User::find($user->id))->not->toBeNull()->and(User::find($admin->id))->not->toBeNull()
        ->and(AuditLog::where('action', 'account.erase')->count())->toBe(0);
});

it('does not fail an owner push when the owner is already gone', function () {
    $car = linkedCar($owner = accountOwner());
    $thread = chatThread($car);
    $message = $thread->messages()->first();
    $owner->forceFill([])->save();
    $car->setRelation('user', null);
    $message->setRelation('thread', $thread->setRelation('car', $car));

    app(NotifyOwnerOfVisitorMessage::class)->handle(new ChatMessageCreated($message));

    expect(true)->toBeTrue();
});

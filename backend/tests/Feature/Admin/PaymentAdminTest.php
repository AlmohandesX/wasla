<?php

use App\Enums\PaymentStatus;
use App\Exceptions\AdminActionException;
use App\Filament\Resources\PaymentResource\Pages\ManagePayments;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Tag;
use App\Services\Payments\Drivers\FakeGateway;
use App\Services\Payments\RenewalService;
use App\Services\Tags\TagAdminService;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

/** Calls the real provider callback for a payment, as production settles. */
function adminPayViaCallback(Payment $payment): void
{
    FakeGateway::setOutcome($payment->reference, PaymentStatus::Paid, $payment->amount_kurus);
    test()->post('/payments/callback/fake', ['reference' => $payment->reference, 'signature' => FakeGateway::sign($payment->reference)])->assertOk();
}

function adminPendingPayment(Tag $tag, ?int $userId = null, array $attrs = []): Payment
{
    return Payment::create($attrs + [
        'tag_id' => $tag->id, 'user_id' => $userId, 'provider' => 'fake',
        'reference' => 'W'.strtoupper(bin2hex(random_bytes(8))), 'amount_kurus' => 79900, 'currency' => 'TRY',
        'status' => PaymentStatus::Pending, 'legal_accepted_at' => now(), 'legal_text_version' => '1',
        'billing_name' => 'Ayşe Yılmaz', 'billing_address' => 'Moda Cad. 5', 'billing_city' => 'İstanbul', 'billing_national_id' => GATEWAY_TEST_TCKN,
    ]);
}

function adminSettledPayment(Tag $tag, ?int $userId = null, array $attrs = []): Payment
{
    $payment = adminPendingPayment($tag, $userId, $attrs);
    adminPayViaCallback($payment);

    return $payment->fresh();
}

function adminRefundAction(Payment $payment): TestAction
{
    return TestAction::make('refund')->table($payment);
}

it('records the tag that settlement extended, or none when it extended nothing', function () {
    [$tag, , $user] = adminClaimedTag();
    expect(adminSettledPayment($tag, $user->id)->extended_tag_id)->toBe($tag->id);

    [$old, , $user2] = adminClaimedTag();
    $new = tagFixture();
    $payment = adminPendingPayment($old, $user2->id);
    app(TagAdminService::class)->replace($old, $new, $this->admin, 'damaged');
    adminPayViaCallback($payment);
    expect($payment->fresh()->extended_tag_id)->toBe($new->id);

    [$revoked, , $user3] = adminClaimedTag();
    app(TagAdminService::class)->revoke($revoked, $this->admin, 'stolen');
    expect(adminSettledPayment($revoked->fresh(), $user3->id)->extended_tag_id)->toBeNull();
});

it('refund marks the payment and shortens by one year', function () {
    [$tag, , $user] = adminClaimedTag();
    $payment = adminSettledPayment($tag, $user->id);
    $extended = $tag->fresh()->expires_at;

    Livewire::test(ManagePayments::class)
        ->callAction(adminRefundAction($payment), ['reason' => 'customer asked'])
        ->assertNotified(__('admin.payments.refunded', ['tag' => $tag->serial]));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Refunded)->and($payment->refunded_at)->not->toBeNull()
        ->and($tag->fresh()->expires_at->equalTo($extended->copy()->subYear()))->toBeTrue();
});

it('shortens the replacement tag that was extended, not the original', function () {
    [$old, , $user] = adminClaimedTag();
    $new = tagFixture();
    $payment = adminPendingPayment($old, $user->id);
    app(TagAdminService::class)->replace($old, $new, $this->admin, 'damaged');
    adminPayViaCallback($payment);
    $oldExpiry = $old->fresh()->expires_at;
    $newExpiry = $new->fresh()->expires_at;

    Livewire::test(ManagePayments::class)->callAction(adminRefundAction($payment), ['reason' => 'refund']);

    expect($new->fresh()->expires_at->equalTo($newExpiry->copy()->subYear()))->toBeTrue()
        ->and($old->fresh()->expires_at->equalTo($oldExpiry))->toBeTrue();
});

it('refunds a payment that extended nothing without shortening anything', function () {
    [$tag, , $user] = adminClaimedTag();
    app(TagAdminService::class)->revoke($tag, $this->admin, 'stolen');
    $payment = adminSettledPayment($tag->fresh(), $user->id);
    $expiry = $tag->fresh()->expires_at;

    Livewire::test(ManagePayments::class)->callAction(adminRefundAction($payment), ['reason' => 'refund']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($tag->fresh()->expires_at->equalTo($expiry))->toBeTrue();
});

it('cannot refund the same payment twice', function () {
    [$tag, , $user] = adminClaimedTag();
    $payment = adminSettledPayment($tag, $user->id);
    $extended = $tag->fresh()->expires_at;

    Livewire::test(ManagePayments::class)->callAction(adminRefundAction($payment), ['reason' => 'first']);
    // The button is gone once refunded, so a second try can only come from a stale page or a direct call.
    Livewire::test(ManagePayments::class)->assertTableActionHidden('refund', $payment);

    expect($tag->fresh()->expires_at->equalTo($extended->copy()->subYear()))->toBeTrue()
        ->and(AuditLog::where('action', 'payment.refund')->count())->toBe(1);

    expect(fn () => app(RenewalService::class)->markRefunded($payment, $this->admin, 'third'))
        ->toThrow(AdminActionException::class, __('admin.payment_not_refundable'));
});

it('refuses to refund a payment that is not paid', function (PaymentStatus $status) {
    [$tag, , $user] = adminClaimedTag();
    $payment = adminPendingPayment($tag, $user->id);
    $payment->update(['status' => $status]);
    $expiry = $tag->fresh()->expires_at;

    expect(fn () => app(RenewalService::class)->markRefunded($payment, $this->admin, 'x'))
        ->toThrow(AdminActionException::class);
    expect($payment->fresh()->status)->toBe($status)->and($tag->fresh()->expires_at->equalTo($expiry))->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
})->with([PaymentStatus::Pending, PaymentStatus::Failed]);

it('refuses on the locked row even when the caller holds a stale paid copy', function () {
    [$tag, , $user] = adminClaimedTag();
    $payment = adminSettledPayment($tag, $user->id);
    $stale = Payment::find($payment->id);
    app(RenewalService::class)->markRefunded($payment, $this->admin, 'first');
    $expiry = $tag->fresh()->expires_at;

    expect(fn () => app(RenewalService::class)->markRefunded($stale, $this->admin, 'again'))->toThrow(AdminActionException::class);
    expect($tag->fresh()->expires_at->equalTo($expiry))->toBeTrue();
});

it('requires a reason to refund and writes an audit record', function () {
    [$tag, , $user] = adminClaimedTag();
    $payment = adminSettledPayment($tag, $user->id);

    Livewire::test(ManagePayments::class)->callAction(adminRefundAction($payment), ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);

    Livewire::test(ManagePayments::class)->callAction(adminRefundAction($payment), ['reason' => 'duplicate charge']);

    $log = AuditLog::where('action', 'payment.refund')->sole();
    expect($log->admin_id)->toBe($this->admin->id)->and($log->subject_id)->toBe($payment->id)
        ->and($log->meta['reason'])->toBe('duplicate charge')->and($log->meta['shortened_tag_id'])->toBe($tag->id);
});

it('tells the admin the refund is recorded here and the money is returned in the provider panel', function () {
    foreach (['tr', 'en'] as $locale) {
        expect(trans('admin.payments.refund_confirm', [], $locale))->toContain('panel');
    }

    $action = Livewire::test(ManagePayments::class)->instance()->getTable()->getAction('refund');
    expect($action->getModalDescription())->toBe(__('admin.payments.refund_confirm'));
});

it('keeps a flag after review and filters on unreviewed flags', function () {
    [$tag, , $user] = adminClaimedTag();
    app(TagAdminService::class)->revoke($tag, $this->admin, 'stolen');
    $flagged = adminSettledPayment($tag->fresh(), $user->id);
    [$tag2, , $user2] = adminClaimedTag();
    $clean = adminSettledPayment($tag2, $user2->id);
    expect($flagged->flag_reason)->toBe('paid_on_revoked_tag');

    Livewire::test(ManagePayments::class)
        ->filterTable('unreviewed_flags')
        ->assertCanSeeTableRecords([$flagged])->assertCanNotSeeTableRecords([$clean])
        ->callAction(TestAction::make('markReviewed')->table($flagged), ['reason' => 'checked with provider'])
        ->assertNotified(__('admin.payments.flag_reviewed'));

    $flagged->refresh();
    expect($flagged->flag_reason)->toBe('paid_on_revoked_tag')->and($flagged->flag_reviewed_at)->not->toBeNull()
        ->and($flagged->flag_reviewed_by)->toBe($this->admin->id)
        ->and(AuditLog::where('action', 'payment.flag_review')->sole()->meta['reason'])->toBe('checked with provider');

    Livewire::test(ManagePayments::class)->filterTable('unreviewed_flags')->assertCanNotSeeTableRecords([$flagged]);
});

it('masks the national ID in the admin panel and cannot search it', function () {
    [$tag, , $user] = adminClaimedTag();
    $payment = adminSettledPayment($tag, $user->id);

    $page = Livewire::test(ManagePayments::class)
        ->assertSee('*********46')
        ->assertDontSee(GATEWAY_TEST_TCKN)->assertDontSee('0000000146')
        ->searchTable(GATEWAY_TEST_TCKN)->assertCanNotSeeTableRecords([$payment]);

    expect($page->instance()->getTable()->getColumn('billing_national_id')->isSearchable())->toBeFalse();
});

function adminExportUrl(string $from, string $to): string
{
    return URL::temporarySignedRoute('admin.payments.export', now()->addMinute(), ['from' => $from, 'to' => $to]);
}

function adminExportRows(string $from, string $to): array
{
    $response = test()->get(adminExportUrl($from, $to))->assertOk();
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());
    $lines = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_values(array_filter(explode("\n", $csv))));
    $header = array_shift($lines);

    return array_map(fn ($l) => array_combine($header, $l), $lines);
}

function adminToday(): string
{
    return now('Europe/Istanbul')->format('Y-m-d');
}

it('exports paid and refunded sales for a date range with billing details', function () {
    [$tag, , $user] = adminClaimedTag();
    $paid = adminSettledPayment($tag, $user->id);
    [$tag2, , $user2] = adminClaimedTag();
    $refunded = adminSettledPayment($tag2, $user2->id);
    app(RenewalService::class)->markRefunded($refunded, $this->admin, 'r');
    [$tag3, , $user3] = adminClaimedTag();
    adminPendingPayment($tag3, $user3->id, ['reference' => 'WPENDING1', 'billing_name' => 'Pending Person']);

    $rows = adminExportRows(adminToday(), adminToday());

    expect($rows)->toHaveCount(2)->and(array_column($rows, 'reference'))->toContain($paid->reference, $refunded->reference)
        ->and(array_keys($rows[0]))->toBe(['reference', 'provider', 'status', 'amount', 'currency', 'paid_at', 'refunded_at', 'tag_serial', 'billing_name', 'billing_address', 'billing_city', 'billing_national_id', 'flag_reason']);
    $row = collect($rows)->firstWhere('reference', $paid->reference);
    expect($row['status'])->toBe('paid')->and($row['amount'])->toBe('799,00')->and($row['billing_name'])->toBe('Ayşe Yılmaz')
        ->and($row['billing_city'])->toBe('İstanbul')->and($row['tag_serial'])->toBe($tag->serial);
});

it('includes the national ID only in the export and audits each export', function () {
    [$tag, , $user] = adminClaimedTag();
    adminSettledPayment($tag, $user->id);

    expect(adminExportRows(adminToday(), adminToday())[0]['billing_national_id'])->toBe(GATEWAY_TEST_TCKN);
    adminExportRows(adminToday(), adminToday());

    $logs = AuditLog::where('action', 'payment.export')->get();
    expect($logs)->toHaveCount(2)->and($logs[0]->admin_id)->toBe($this->admin->id)
        ->and($logs[0]->meta['from'])->toBe(adminToday())
        ->and(json_encode($logs->pluck('meta')))->not->toContain(GATEWAY_TEST_TCKN);
});

it('does not log the national ID during an export', function () {
    [$tag, , $user] = adminClaimedTag();
    adminSettledPayment($tag, $user->id);
    Log::spy();

    adminExportRows(adminToday(), adminToday());

    assertNotLogged(GATEWAY_TEST_TCKN);
});

it('neutralizes CSV formula injection in every text column', function () {
    [$tag, , $user] = adminClaimedTag();
    adminSettledPayment($tag, $user->id, [
        'billing_name' => '=HYPERLINK("http://x")', 'billing_address' => '+1+1', 'billing_city' => '@SUM(A1)', 'billing_national_id' => '-1',
    ]);

    $row = adminExportRows(adminToday(), adminToday())[0];

    expect($row['billing_name'])->toBe("'=HYPERLINK(\"http://x\")")->and($row['billing_address'])->toBe("'+1+1")
        ->and($row['billing_city'])->toBe("'@SUM(A1)")->and($row['billing_national_id'])->toBe("'-1");
});

it('converts an Istanbul date range to UTC on both ends', function () {
    // 2030-03-10 in Istanbul is 2030-03-09 21:00:00 UTC through 2030-03-10 20:59:59 UTC.
    $paidAt = ['first' => '2030-03-09 21:00:00', 'last' => '2030-03-10 20:59:59', 'before' => '2030-03-09 20:59:59', 'after' => '2030-03-10 21:00:00'];
    $payments = [];
    foreach ($paidAt as $name => $utc) {
        [$tag, , $user] = adminClaimedTag();
        $payments[$name] = adminSettledPayment($tag, $user->id);
        $payments[$name]->update(['paid_at' => Carbon::parse($utc, 'UTC')]);
    }

    $refs = array_column(adminExportRows('2030-03-10', '2030-03-10'), 'reference');

    expect($refs)->toHaveCount(2)->toContain($payments['first']->reference, $payments['last']->reference)
        ->and($refs)->not->toContain($payments['before']->reference, $payments['after']->reference);
});

it('keeps the export admin-only', function () {
    $url = adminExportUrl('2030-01-01', '2030-01-02');
    auth()->logout();
    app('auth')->forgetGuards();
    $this->get($url)->assertForbidden();
    $this->actingAs(owner(['email_verified_at' => now()]))->get($url)->assertForbidden();
    expect(AuditLog::where('action', 'payment.export')->count())->toBe(0);
});

it('rejects an invalid or reversed export range', function () {
    $this->get(adminExportUrl('nope', '2030-01-02'))->assertStatus(302);
    $this->get(adminExportUrl('2030-01-03', '2030-01-02'))->assertStatus(302);
    expect(AuditLog::where('action', 'payment.export')->count())->toBe(0);
});

it('refunds the live tag after the paid tag was replaced following payment', function () {
    [$old, , $user] = adminClaimedTag();
    $payment = adminSettledPayment($old, $user->id);
    $new = tagFixture();
    app(TagAdminService::class)->replace($old, $new, $this->admin, 'damaged');
    $newExpiry = $new->fresh()->expires_at;
    expect($payment->extended_tag_id)->toBe($old->id);

    Livewire::test(ManagePayments::class)->callAction(adminRefundAction($payment), ['reason' => 'refund'])
        ->assertNotified(__('admin.payments.refunded', ['tag' => $new->serial]));

    expect($new->fresh()->expires_at->equalTo($newExpiry->copy()->subYear()))->toBeTrue()
        ->and(AuditLog::where('action', 'payment.refund')->sole()->meta['shortened_tag_id'])->toBe($new->id);
});

it('says in the notification that nothing was shortened when no tag was extended', function () {
    [$tag, , $user] = adminClaimedTag();
    app(TagAdminService::class)->revoke($tag, $this->admin, 'stolen');
    $payment = adminSettledPayment($tag->fresh(), $user->id);

    Livewire::test(ManagePayments::class)->callAction(adminRefundAction($payment), ['reason' => 'refund'])
        ->assertNotified(__('admin.payments.refunded', ['tag' => __('admin.payments.no_tag')]));
});

it('refuses an unsigned or expired export link and writes no audit row', function () {
    $this->get(route('admin.payments.export', ['from' => '2030-01-01', 'to' => '2030-01-02']))->assertForbidden();
    $expired = URL::temporarySignedRoute('admin.payments.export', now()->subMinute(), ['from' => '2030-01-01', 'to' => '2030-01-02']);
    $this->get($expired)->assertForbidden();

    expect(AuditLog::where('action', 'payment.export')->count())->toBe(0);
});

it('refuses a session invalidated by a password change and a suspended admin on the export', function () {
    $url = adminExportUrl('2030-01-01', '2030-01-02');
    $this->withSession(['password_hash_web' => 'hash-from-before-the-password-change'])->get($url)->assertRedirect();
    expect(AuditLog::where('action', 'payment.export')->count())->toBe(0);

    $suspended = adminUser(['suspended_at' => now()]);
    auth()->logout();
    app('auth')->forgetGuards();
    $this->actingAs($suspended)->get($url)->assertForbidden();
    expect(AuditLog::where('action', 'payment.export')->count())->toBe(0);
});

it('sends the export as a private attachment', function () {
    $response = $this->get(adminExportUrl('2030-01-01', '2030-01-02'))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('wasla-sales-20300101-20300102.csv')
        ->and($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('reaches the signed export link from the panel action', function () {
    $component = Livewire::test(ManagePayments::class)
        ->callAction(TestAction::make('export')->table(), ['from' => '2030-01-01', 'to' => '2030-01-02']);

    $component->assertRedirect();
    $target = $component->effects['redirect'];
    expect($target)->toContain('signature=')->and($target)->toContain('expires=');
    $this->get($target)->assertOk();
});

it('translates the flag reason in the table', function () {
    [$tag, , $user] = adminClaimedTag();
    app(TagAdminService::class)->revoke($tag, $this->admin, 'stolen');
    adminSettledPayment($tag->fresh(), $user->id);

    Livewire::test(ManagePayments::class)->assertSee(__('admin.payments.flags.paid_on_revoked_tag'));
});

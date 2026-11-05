<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Payments\Drivers\FakeGateway;
use App\Services\Payments\RenewalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Local development checkout page for FakeGateway. Not registered, and 404 at runtime, under any other gateway. */
class FakeGatewayController extends Controller
{
    public function show(string $reference)
    {
        $this->payment($reference);

        return view('fake-gateway.checkout', ['reference' => $reference]);
    }

    public function complete(string $reference, string $outcome): RedirectResponse
    {
        $payment = $this->payment($reference);
        $paid = $outcome === 'pay';

        FakeGateway::setOutcome($reference, $paid ? PaymentStatus::Paid : PaymentStatus::Failed, $paid ? $payment->amount_kurus : 0);

        // The same signed callback a real provider would send.
        app(RenewalService::class)->handleCallback(new FakeGateway, Request::create('/payments/callback/fake', 'POST', [
            'reference' => $reference,
            'signature' => FakeGateway::sign($reference),
        ]));

        return redirect('/payments/return');
    }

    private function payment(string $reference): Payment
    {
        abort_unless(config('wasla.payment_gateway') === 'fake' && ! app()->isProduction(), 404);

        return Payment::query()->where('reference', $reference)->where('provider', 'fake')->firstOrFail();
    }
}

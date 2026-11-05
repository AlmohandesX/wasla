<?php

namespace App\Http\Controllers;

use App\Services\Payments\GatewayManager;
use App\Services\Payments\InvalidCallback;
use App\Services\Payments\RenewalService;
use App\Services\Payments\UnknownPaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentCallbackController extends Controller
{
    public function callback(Request $request, string $provider, GatewayManager $gateways): Response
    {
        try {
            $gateway = $gateways->driver($provider);
        } catch (UnknownPaymentGateway) {
            abort(404);
        }

        try {
            app(RenewalService::class)->handleCallback($gateway, $request);
        } catch (InvalidCallback) {
            // One answer for every refusal, so nothing says whether a reference exists.
            return $this->refusal($request, 'Invalid callback.', 400);
        } catch (Throwable $e) {
            // Class only: a provider or driver message could carry request data. 503 makes the provider retry.
            Log::error('Payment callback could not be processed.', ['provider' => $provider, 'exception' => $e::class]);

            return $this->refusal($request, 'Try again later.', 503);
        }

        return $gateway->callbackResponse();
    }

    /** iyzico's callback is the owner's browser, so anything but a JSON webhook gets the translated page. The status stays. */
    private function refusal(Request $request, string $plain, int $status): Response
    {
        return $request->isJson() ? response($plain, $status) : response()->view('payments.return', [], $status);
    }

    /** Where the provider sends the owner's browser afterwards. It reads nothing and changes nothing. */
    public function return()
    {
        return view('payments.return');
    }
}

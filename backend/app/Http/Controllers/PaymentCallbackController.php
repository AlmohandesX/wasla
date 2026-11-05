<?php

namespace App\Http\Controllers;

use App\Services\Payments\GatewayManager;
use App\Services\Payments\InvalidCallback;
use App\Services\Payments\RenewalService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentCallbackController extends Controller
{
    public function callback(Request $request, string $provider, GatewayManager $gateways): Response
    {
        try {
            $gateway = $gateways->driver($provider);
        } catch (RuntimeException) {
            abort(404);
        }

        try {
            app(RenewalService::class)->handleCallback($gateway, $request);
        } catch (InvalidCallback) {
            // One answer for every refusal, so nothing says whether a reference exists.
            return response('Invalid callback.', 400);
        } catch (Throwable $e) {
            // Class only: a provider or driver message could carry request data. 503 makes the provider retry.
            Log::error('Payment callback could not be processed.', ['provider' => $provider, 'exception' => $e::class]);

            return response('Try again later.', 503);
        }

        return $gateway->callbackResponse();
    }

    /** Where the provider sends the owner's browser afterwards. It reads nothing and changes nothing. */
    public function return()
    {
        return view('payments.return');
    }
}

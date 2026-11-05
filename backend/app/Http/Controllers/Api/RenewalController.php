<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StartRenewalRequest;
use App\Http\Resources\PaymentResource;
use App\Exceptions\RenewalNoTag;
use App\Models\Car;
use App\Models\Payment;
use App\Services\Payments\RenewalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RenewalController extends Controller
{
    public function store(StartRenewalRequest $request, Car $car, RenewalService $renewals): JsonResponse
    {
        $tag = $car->tag ?? throw new RenewalNoTag;

        [$session, $payment] = $renewals->startWithPayment($request->user(), $tag, $request->billing(), (string) $request->ip());

        return response()->json(['data' => [
            ...(new PaymentResource($payment))->resolve($request),
            'redirect_url' => $session->redirectUrl,
        ]], 201);
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return new PaymentResource($payment);
    }
}

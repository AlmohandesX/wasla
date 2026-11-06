<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cars\DeleteDeviceTokenRequest;
use App\Http\Requests\Cars\StoreDeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeviceTokenController extends Controller
{
    /** A device belongs to whoever registered it last, so a shared phone follows the signed-in account. */
    public function store(StoreDeviceTokenRequest $request): Response
    {
        $token = DeviceToken::query()->firstOrNew(['token' => $request->validated('token')]);
        $token->fill(['user_id' => $request->user()->id, 'platform' => $request->validated('platform')])->save();

        return response()->noContent();
    }

    /** Only the caller's own tokens are looked up, so another account's token is never confirmed or removed. */
    public function destroy(DeleteDeviceTokenRequest $request): Response
    {
        $token = $request->user()->deviceTokens()->where('token', $request->validated('token'))->first();
        if ($token !== null) {
            Gate::authorize('delete', $token);
            $token->delete();
        }

        return response()->noContent();
    }
}

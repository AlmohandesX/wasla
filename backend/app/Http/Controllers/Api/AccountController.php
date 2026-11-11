<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AdminAccountProtected;
use App\Exceptions\InvalidGoogleToken;
use App\Exceptions\InvalidPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Services\Account\AccountEraser;
use App\Services\Auth\Google\GoogleTokenVerifier;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function destroy(DeleteAccountRequest $request, AccountEraser $eraser, GoogleTokenVerifier $google): Response
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            throw new AdminAccountProtected;
        }

        if (! $user->hasPassword()) {
            // The token must belong to the Google account this owner signs in with.
            if ($google->verify($request->string('google_id_token')->toString())->sub !== $user->google_id) {
                throw new InvalidGoogleToken;
            }
        } elseif (! Hash::check($request->string('password')->toString(), $user->password)) {
            throw new InvalidPassword;
        }

        $eraser->erase($user);

        return response()->noContent();
    }
}

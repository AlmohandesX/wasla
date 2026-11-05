<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AdminAccountProtected;
use App\Exceptions\InvalidPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Services\Account\AccountEraser;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function destroy(DeleteAccountRequest $request, AccountEraser $eraser): Response
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            throw new AdminAccountProtected;
        }

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            throw new InvalidPassword;
        }

        $eraser->erase($user);

        return response()->noContent();
    }
}

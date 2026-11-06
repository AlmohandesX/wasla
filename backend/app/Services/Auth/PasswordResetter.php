<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** The one place a password is reset with a broker token, shared by the API and the web page. */
class PasswordResetter
{
    /** True when the token was valid and the password changed; every API token and push token of the owner is revoked. */
    public function reset(string $email, string $token, string $password): bool
    {
        $status = Password::reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                // Someone else may have the phone: it stops getting this account's pushes too.
                $user->deviceTokens()->delete();
                event(new PasswordReset($user));
            },
        );

        return $status === Password::PASSWORD_RESET;
    }
}

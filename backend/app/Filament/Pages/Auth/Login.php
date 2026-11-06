<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\Auth\LoginThrottle;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $throttle = app(LoginThrottle::class);

        // Same normalisation as the API login: throttle on the stored address so collation variants share one counter.
        $normalized = mb_strtolower(trim((string) $data['email']));
        $user = User::where('email', $normalized)->first();
        $email = $user ? mb_strtolower($user->email) : $normalized;
        if ($user && $email !== $normalized) {
            $user = null;
        }

        if ($throttle->tooManyAttempts($email)) {
            throw ValidationException::withMessages(['data.email' => __('wasla.too_many_attempts')]);
        }

        // One hash check on every path, so timing does not reveal whether an account exists.
        $valid = Hash::check((string) $data['password'], $user?->password ?? config('wasla.dummy_password_hash'));

        // Owners, suspended admins and unknown emails fail exactly like a wrong password.
        if (! $user || ! $valid || ! $user->isAdmin() || $user->isSuspended()) {
            $throttle->hit($email);
            $this->throwFailureValidationException();
        }

        $throttle->clearAccount($email);
        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        session()->regenerate();

        return app(LoginResponse::class);
    }
}

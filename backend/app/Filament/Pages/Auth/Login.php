<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\Auth\LoginThrottle;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** The web panel's only way in: username and password. Owners have no username, so they can never sign in here. */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getUsernameFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
        ]);
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label(__('admin.login.username'))
            ->required()
            ->maxLength(32)
            ->autocomplete('username')
            ->autofocus();
    }

    public function authenticate(): ?LoginResponse
    {
        // Per-IP burst control (a one-minute rate limit, never a lockout): this override replaces Filament's own
        // rateLimit(), and Livewire's update route is outside the `throttle:auth` limiter the API login uses.
        $burstKey = 'admin-login-ip:'.request()->ip();
        if (RateLimiter::tooManyAttempts($burstKey, 10)) {
            throw ValidationException::withMessages(['data.username' => __('wasla.too_many_attempts')]);
        }
        RateLimiter::hit($burstKey, 60);

        $data = $this->form->getState();
        $throttle = app(LoginThrottle::class);

        // Usernames are stored lowercase. The column collation treats some different strings as equal, so the
        // throttle counts on the stored name and only an exact match is the account.
        $normalized = User::normalizeUsername((string) $data['username']);
        $user = User::where('username', $normalized)->first();
        $key = 'admin:'.($user ? $user->username : $normalized);
        if ($user && $user->username !== $normalized) {
            $user = null;
        }

        if ($throttle->tooManyAttempts($key)) {
            throw ValidationException::withMessages(['data.username' => __('wasla.too_many_attempts')]);
        }

        // One hash check on every path, so timing does not reveal whether an account exists.
        $valid = Hash::check((string) $data['password'], $user?->password ?? config('wasla.dummy_password_hash'));

        // Non-admins, suspended admins and unknown usernames fail exactly like a wrong password.
        if (! $user || ! $valid || ! $user->isAdmin() || $user->isSuspended()) {
            $throttle->hit($key);
            $this->throwFailureValidationException();
        }

        $throttle->clearAccount($key);
        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\PasswordResetter;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    private const TOKEN_DAYS = 90;

    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly PasswordResetter $resetter,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->locale = $request->input('locale', config('app.locale'));
        $user->role = 'owner';
        $user->privacy_accepted_at = now();
        $user->privacy_notice_version = config('wasla.privacy_notice_version');
        $user->save();

        event(new Registered($user));

        return response()->json($this->session($user), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $normalized = mb_strtolower(trim($request->string('email')->toString()));
        $ip = (string) $request->ip();

        // The column collation treats some different strings as equal (zero-width characters,
        // accents). Throttle on the stored address so those variants share one counter, and
        // only accept an exact lowercase match as the account.
        $user = User::where('email', $normalized)->first();
        $email = $user ? mb_strtolower($user->email) : $normalized;
        if ($user && $email !== $normalized) {
            $user = null;
        }

        if ($this->throttle->tooManyAttempts($email, $ip)) {
            $retryAfter = $this->throttle->availableIn($email, $ip);

            return response()->json([
                'code' => 'too_many_attempts',
                'message' => __('wasla.too_many_attempts'),
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        // Exactly one hash check on both paths. Unknown emails check against a constant bcrypt hash
        // (no hashing during the request), so timing does not reveal whether an email exists.
        $valid = Hash::check($request->input('password'), $user?->password ?? config('wasla.dummy_password_hash'));

        if (! $user || ! $valid) {
            $this->throttle->hit($email, $ip);

            return response()->json([
                'code' => 'invalid_credentials',
                'message' => __('wasla.invalid_credentials'),
            ], 401);
        }

        $this->throttle->clearAccount($email);

        if ($user->isSuspended()) {
            return response()->json([
                'code' => 'account_suspended',
                'message' => __('wasla.account_suspended'),
            ], 403);
        }

        return response()->json($this->session($user));
    }

    public function logout(LogoutRequest $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        // Identical answer whether or not the email exists.
        return response()->json(['message' => __('wasla.reset_link_sent')]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        if (! $this->resetter->reset($request->input('email'), $request->input('token'), $request->input('password'))) {
            return response()->json([
                'code' => 'invalid_reset_token',
                'message' => __('wasla.invalid_reset_token'),
            ], 422);
        }

        return response()->json(['message' => __('wasla.password_reset')]);
    }

    public function resend(ResendVerificationRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => __('wasla.verification_sent')]);
    }

    /** @return array{token: string, user: array<string, mixed>} */
    private function session(User $user): array
    {
        $token = $user->createToken('mobile', ['*'], now()->addDays(self::TOKEN_DAYS));

        return ['token' => $token->plainTextToken, 'user' => $this->present($user)];
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'role' => $user->role,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }
}

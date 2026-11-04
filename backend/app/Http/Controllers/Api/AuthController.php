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
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const TOKEN_DAYS = 90;

    public function __construct(private readonly LoginThrottle $throttle) {}

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
        $email = $request->string('email')->toString();
        $ip = (string) $request->ip();

        if ($this->throttle->tooManyAttempts($email, $ip)) {
            $retryAfter = $this->throttle->availableIn($email, $ip);

            return response()->json([
                'code' => 'too_many_attempts',
                'message' => __('wasla.too_many_attempts'),
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $user = User::where('email', mb_strtolower(trim($email)))->first();

        // Always run one hash check so unknown emails cost the same as wrong passwords.
        $valid = Hash::check($request->input('password'), $user?->password ?? Hash::make(Str::random(16)));

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
        $status = Password::reset(
            $request->only('email', 'password', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
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

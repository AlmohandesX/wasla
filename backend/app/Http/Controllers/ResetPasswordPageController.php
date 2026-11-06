<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\WebResetPasswordRequest;
use App\Services\Auth\PasswordResetter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The page a password reset email links to. It works in any mail app and browser, with or without the app installed. */
class ResetPasswordPageController extends Controller
{
    public function __construct(private readonly PasswordResetter $resetter) {}

    public function show(Request $request): Response
    {
        $email = $request->query('email');
        $token = $request->query('token');

        if (! is_string($email) || ! is_string($token) || $email === '' || $token === '') {
            return self::page('', '', 422, errors: [__('wasla.reset_incomplete')], showForm: false);
        }

        return self::page($email, $token);
    }

    public function update(WebResetPasswordRequest $request): Response
    {
        if (! $this->resetter->reset($request->input('email'), $request->input('token'), $request->input('password'))) {
            // Same answer for a wrong token, an expired one and an unknown address.
            return self::page($request->input('email'), $request->input('token'), 422, errors: [__('wasla.invalid_reset_token')]);
        }

        return self::page('', '', done: true);
    }

    /** @param  list<string>  $errors */
    public static function page(string $email, string $token, int $status = 200, array $errors = [], bool $done = false, bool $showForm = true): Response
    {
        return response()->view('auth.reset-password', [
            'email' => $email,
            'token' => $token,
            'errors_list' => $errors,
            'done' => $done,
            'showForm' => $showForm,
            'appLink' => 'wasla://reset-password?'.http_build_query(['token' => $token, 'email' => $email]),
        ], $status)->header('Referrer-Policy', 'no-referrer');
    }
}

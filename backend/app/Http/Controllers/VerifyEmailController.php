<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /** The route is signed and time limited; the hash binds the link to the current email address. */
    public function __invoke(Request $request, int $id, string $hash): View
    {
        $user = User::findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        app()->setLocale($user->preferredLocale());

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return view('auth.email-verified');
    }
}

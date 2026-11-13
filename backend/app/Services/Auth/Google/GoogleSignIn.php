<?php

namespace App\Services\Auth\Google;

use App\Exceptions\AdminAccountInApp;
use App\Exceptions\GoogleAccountConflict;
use App\Exceptions\GoogleConsentRequired;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Str;

/**
 * Turns a verified Google identity into an owner account:
 *  - the account already linked to this Google ID;
 *  - otherwise the account with the same (Google-verified) email, which gets linked;
 *  - otherwise a new owner with no password, once the privacy notice is accepted.
 */
class GoogleSignIn
{
    /** @return array{0: User, 1: bool} the account, and whether it was just created */
    public function resolve(GoogleIdentity $identity, bool $privacyConsent, string $locale): array
    {
        $user = User::where('google_id', $identity->sub)->first();
        if ($user !== null) {
            $this->assertAppAccount($user);

            return [$user, false];
        }

        $user = User::where('email', $identity->email)->first();
        if ($user !== null) {
            // The collation treats some different addresses as equal; only an exact match is this person's account.
            // An account already tied to another Google ID is never re-linked.
            if (mb_strtolower((string) $user->email) !== $identity->email || $user->google_id !== null) {
                throw new GoogleAccountConflict;
            }
            $this->assertAppAccount($user);

            // Google has verified the address, which is what our own verification email would have proved.
            $user->forceFill(['google_id' => $identity->sub, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

            return [$user, false];
        }

        if (! $privacyConsent) {
            throw new GoogleConsentRequired;
        }

        $user = new User([
            'name' => $identity->name !== '' ? Str::limit($identity->name, 100, '') : Str::before($identity->email, '@'),
            'email' => $identity->email,
        ]);
        $user->forceFill([
            'google_id' => $identity->sub,
            'email_verified_at' => now(),
            'locale' => $locale,
            'role' => 'owner',
            'privacy_accepted_at' => now(),
            'privacy_notice_version' => config('wasla.privacy_notice_version'),
        ])->save();

        event(new Registered($user));

        return [$user, true];
    }

    private function assertAppAccount(User $user): void
    {
        if ($user->isAdmin()) {
            throw new AdminAccountInApp;
        }
    }
}

<?php

namespace App\Services\Auth;

use App\Exceptions\AdminActionException;
use App\Models\User;
use App\Services\Concerns\AuditsAdminActions;
use Illuminate\Support\Facades\DB;

class UserAdminService
{
    use AuditsAdminActions;

    /** Their tags turn neutral because PublicTagStateResolver checks suspension; expiry dates keep running. */
    public function suspend(User $user, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        if ($user->is($admin)) {
            throw AdminActionException::because('cannot_suspend_self');
        }
        $this->guardAdminAccount($user, $admin);

        DB::transaction(function () use ($user, $admin, $reason) {
            $user->forceFill(['suspended_at' => $user->suspended_at ?? now()])->save();
            $user->tokens()->delete();
            $user->deviceTokens()->delete();
            $this->audit($admin, 'user.suspend', 'user', $user->id, $reason);
        });
    }

    public function unsuspend(User $user, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);
        $this->guardAdminAccount($user, $admin);

        DB::transaction(function () use ($user, $admin, $reason) {
            $user->forceFill(['suspended_at' => null])->save();
            $this->audit($admin, 'user.unsuspend', 'user', $user->id, $reason);
        });
    }

    /** Panel accounts are a super admin's to suspend or reinstate; a plain admin acts on owners only. */
    private function guardAdminAccount(User $user, User $admin): void
    {
        if ($user->isAdmin() && ! $admin->isSuperAdmin()) {
            throw AdminActionException::because('needs_super_admin');
        }
    }
}

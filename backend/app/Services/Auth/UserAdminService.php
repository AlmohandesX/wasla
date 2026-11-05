<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UserAdminService
{
    /** Their tags turn neutral because PublicTagStateResolver checks suspension; expiry dates keep running. */
    public function suspend(User $user, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        DB::transaction(function () use ($user, $admin, $reason) {
            $user->forceFill(['suspended_at' => $user->suspended_at ?? now()])->save();
            $user->tokens()->delete();
            $this->audit($admin, 'user.suspend', $user, $reason);
        });
    }

    public function unsuspend(User $user, User $admin, string $reason): void
    {
        $reason = $this->requireReason($reason);

        DB::transaction(function () use ($user, $admin, $reason) {
            $user->forceFill(['suspended_at' => null])->save();
            $this->audit($admin, 'user.unsuspend', $user, $reason);
        });
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return $reason;
    }

    private function audit(User $admin, string $action, User $user, string $reason): void
    {
        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'meta' => ['reason' => $reason],
        ]);
    }
}

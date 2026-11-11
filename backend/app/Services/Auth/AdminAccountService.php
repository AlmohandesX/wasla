<?php

namespace App\Services\Auth;

use App\Exceptions\AdminActionException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Web panel accounts: a username and a password, no email, and never usable in the mobile app. */
class AdminAccountService
{
    public const MIN_PASSWORD = 12;

    /** Only a super admin creates admins. */
    public function create(User $actor, string $name, string $username, #[\SensitiveParameter] string $password): User
    {
        if (! $actor->isSuperAdmin() || $actor->isSuspended()) {
            throw AdminActionException::because('needs_super_admin');
        }

        return DB::transaction(function () use ($actor, $name, $username, $password) {
            $admin = $this->save(new User, $name, $username, $password, 'admin');
            AuditLog::create([
                'admin_id' => $actor->id,
                'action' => 'admin.create',
                'subject_type' => 'user',
                'subject_id' => $admin->id,
                'meta' => ['username' => $admin->username],
            ]);

            return $admin;
        });
    }

    /**
     * The seeder's and the console's way in, where there is no acting admin. Creates the account, or updates the
     * one with that username (name, password, role), and reinstates it if it was suspended.
     *
     * @param  'super_admin'|'admin'  $role
     */
    public function upsert(string $role, string $name, string $username, #[\SensitiveParameter] string $password): User
    {
        if (! in_array($role, User::ADMIN_ROLES, true)) {
            throw new \InvalidArgumentException("Not a panel role [$role].");
        }
        $existing = User::where('username', User::normalizeUsername($username))->first();
        if ($existing !== null && ! $existing->isAdmin()) {
            throw new \InvalidArgumentException('That username belongs to an owner account.');
        }

        return $this->save($existing ?? new User, $name, $username, $password, $role);
    }

    private function save(User $user, string $name, string $username, string $password, string $role): User
    {
        $user->forceFill([
            'name' => trim($name),
            'username' => User::normalizeUsername($username),
            'password' => $password,
            'role' => $role,
            'locale' => $user->locale ?? config('app.locale'),
            'suspended_at' => null,
        ])->save();

        return $user;
    }
}

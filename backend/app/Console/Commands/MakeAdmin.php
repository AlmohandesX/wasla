<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\AdminAccountService;
use Illuminate\Console\Command;

/** Creates a web panel account, or resets the name, password and role of the one with that username. */
class MakeAdmin extends Command
{
    protected $signature = 'wasla:make-admin {username} {--super : Make it a super admin, who can create admins} {--name= : Display name}';

    protected $description = 'Create a web panel admin (username + password), or reset an existing one';

    public function handle(AdminAccountService $accounts): int
    {
        $username = User::normalizeUsername((string) $this->argument('username'));
        if (preg_match(User::USERNAME_PATTERN, $username) !== 1) {
            $this->error('Usernames are 3 to 32 lowercase letters, digits, dots, dashes or underscores.');

            return self::FAILURE;
        }

        $existing = User::where('username', $username)->first();
        if ($existing !== null && ! $existing->isAdmin()) {
            $this->error('That username belongs to an owner account.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (min '.AdminAccountService::MIN_PASSWORD.' characters)');
        if (strlen($password) < AdminAccountService::MIN_PASSWORD) {
            $this->error('Password too short.');

            return self::FAILURE;
        }

        $role = $this->option('super') ? 'super_admin' : 'admin';
        $name = (string) ($this->option('name') ?: $existing?->name ?: ($role === 'super_admin' ? 'Super Admin' : 'Admin'));
        if ($existing?->isSuspended()) {
            $this->warn('This account was suspended. It is being reinstated.');
        }

        $accounts->upsert($role, $name, $username, $password);
        $this->info(($existing ? 'Updated' : 'Created')." {$role} [{$username}]. Sign in at /admin.");

        return self::SUCCESS;
    }
}

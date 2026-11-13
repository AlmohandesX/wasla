<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Auth\AdminAccountService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The first web panel account: `php artisan db:seed --class=SuperAdminSeeder`, with SUPER_ADMIN_USERNAME and
 * SUPER_ADMIN_PASSWORD (and optionally SUPER_ADMIN_NAME) set in .env. Running it again updates the same account,
 * which is also how a forgotten super admin password is reset.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(AdminAccountService $accounts): void
    {
        $config = config('wasla.super_admin');
        $username = User::normalizeUsername((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');

        if (preg_match(User::USERNAME_PATTERN, $username) !== 1) {
            throw new RuntimeException('Set SUPER_ADMIN_USERNAME: 3 to 32 lowercase letters, digits, dots, dashes or underscores.');
        }
        if (strlen($password) < AdminAccountService::MIN_PASSWORD) {
            throw new RuntimeException('Set SUPER_ADMIN_PASSWORD to at least '.AdminAccountService::MIN_PASSWORD.' characters.');
        }

        $accounts->upsert('super_admin', (string) ($config['name'] ?: 'Super Admin'), $username, $password);
        $this->command?->info("Super admin [$username] is ready. Remove SUPER_ADMIN_PASSWORD from .env now.");
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class MakeAdmin extends Command
{
    protected $signature = 'wasla:make-admin {email}';

    protected $description = 'Create an admin account, or promote an existing user to admin';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            $this->promote($user);

            return self::SUCCESS;
        }

        $password = (string) $this->secret('Password (min 12 characters)');
        if (strlen($password) < 12) {
            $this->error('Password too short.');

            return self::FAILURE;
        }

        User::create(['name' => 'Admin', 'email' => $email, 'password' => $password])
            ->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();
        $this->info("Admin {$email} created.");

        return self::SUCCESS;
    }

    private function promote(User $user): void
    {
        $wasAdmin = $user->isAdmin();

        if ($user->isSuspended()) {
            $this->warn('This account was suspended. It is being reinstated.');
        }
        if (! $wasAdmin) {
            $this->warn('This account was an owner. It now has admin access.');
        }

        $user->forceFill(['role' => 'admin', 'suspended_at' => null, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

        // The command has no acting admin. An existing admin account being re-enabled is its own attribution;
        // otherwise there is nobody to attribute it to and the warning above is the only record.
        if ($wasAdmin) {
            AuditLog::create([
                'admin_id' => $user->id,
                'action' => 'user.make_admin_command',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'meta' => ['source' => 'cli'],
            ]);
        }

        $this->info("{$user->email} is now an admin.");
    }
}

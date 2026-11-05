<?php

namespace App\Console\Commands;

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
            $user->forceFill(['role' => 'admin', 'suspended_at' => null, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            $this->info("{$email} is now an admin.");

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
}

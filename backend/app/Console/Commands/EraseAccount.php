<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Account\AccountEraser;
use Illuminate\Console\Command;

class EraseAccount extends Command
{
    protected $signature = 'wasla:erase-account {email : The owner account to erase} {--admin= : Email of the admin doing this, for the audit row}';

    protected $description = 'Support route: erase an owner account, including a suspended one. Admin accounts are refused.';

    public function handle(AccountEraser $eraser): int
    {
        $admin = User::query()->where('role', 'admin')->where('email', (string) $this->option('admin'))->first();
        if ($admin === null) {
            $this->error('Pass --admin with the email of an admin account.');

            return self::FAILURE;
        }
        if ($admin->isSuspended()) {
            $this->error('The --admin account is suspended and cannot act.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        if ($user->isAdmin()) {
            $this->error('Admin accounts cannot be erased here.');

            return self::FAILURE;
        }

        if (! $this->confirm('Erase this account permanently?')) {
            return self::FAILURE;
        }

        $id = $user->id;
        $eraser->erase($user);

        // The id only: the email is personal data and went with the account.
        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'account.erase',
            'subject_type' => 'user',
            'subject_id' => $id,
            'meta' => ['via' => 'console'],
        ]);

        $this->info('Account erased.');

        return self::SUCCESS;
    }
}

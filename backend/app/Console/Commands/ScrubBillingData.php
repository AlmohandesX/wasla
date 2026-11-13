<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Command;

class ScrubBillingData extends Command
{
    public const PENDING_DAYS = 7;

    protected $signature = 'wasla:scrub-billing';

    protected $description = 'Null the billing fields of failed and abandoned payments; amount, provider, reference and status stay';

    public function handle(): int
    {
        // Base query on purpose: no model events and no decrypting. Paid and refunded rows never match.
        $count = Payment::query()
            ->where(fn ($q) => $q->where('status', PaymentStatus::Failed->value)
                ->orWhere(fn ($q) => $q->where('status', PaymentStatus::Pending->value)
                    ->where('created_at', '<', now()->subDays(self::PENDING_DAYS))))
            ->toBase()
            ->update(['billing_name' => null, 'billing_address' => null, 'billing_city' => null, 'billing_national_id' => null]);

        $this->info("Scrubbed billing data from $count payments.");

        return self::SUCCESS;
    }
}

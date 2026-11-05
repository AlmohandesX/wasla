<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place the national ID leaves the system in clear: an admin-only CSV of paid and refunded sales, written
 * straight to the response a row at a time. Nothing here logs a row; each export leaves one audit record.
 */
class SalesExporter
{
    public const TIMEZONE = 'Europe/Istanbul';

    private const HEADER = [
        'reference', 'provider', 'status', 'amount', 'currency', 'paid_at', 'refunded_at', 'tag_serial',
        'billing_name', 'billing_address', 'billing_city', 'billing_national_id', 'flag_reason',
    ];

    /** Both ends are inclusive instants, in any timezone; they are compared in UTC, which is how paid_at is stored. */
    public function toCsv(Carbon $from, Carbon $to, User $admin): StreamedResponse
    {
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();

        $query = Payment::query()
            ->with('tag:id,serial')
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
            ->whereBetween('paid_at', [$fromUtc, $toUtc]);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'payment.export',
            'subject_type' => 'payment_export',
            'subject_id' => $admin->id,
            'meta' => [
                'from' => $from->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d'),
                'to' => $to->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d'),
                'rows' => (clone $query)->count(),
                'reason' => 'sales export',
            ],
        ]);

        $name = 'wasla-sales-'.$from->copy()->setTimezone(self::TIMEZONE)->format('Ymd').'-'.$to->copy()->setTimezone(self::TIMEZONE)->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // so spreadsheets read the Turkish letters as UTF-8
            fputcsv($out, self::HEADER, ',', '"', '');

            foreach ($query->lazyById(500) as $payment) {
                fputcsv($out, array_map(self::cell(...), [
                    $payment->reference,
                    $payment->provider,
                    $payment->status->value,
                    Kurus::toDecimal($payment->amount_kurus),
                    $payment->currency,
                    $payment->paid_at?->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
                    $payment->refunded_at?->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
                    $payment->tag?->serial,
                    $payment->billing_name,
                    $payment->billing_address,
                    $payment->billing_city,
                    $payment->billing_national_id,
                    $payment->flag_reason,
                ]), ',', '"', '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** A cell a spreadsheet could read as a formula gets a leading apostrophe. */
    public static function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }
}

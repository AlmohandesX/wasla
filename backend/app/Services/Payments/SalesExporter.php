<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\Concerns\AuditsAdminActions;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place the national ID leaves the system in clear: an admin-only CSV of paid and refunded sales, written
 * straight to the response a row at a time. Nothing here logs a row; each export leaves one audit record.
 */
class SalesExporter
{
    use AuditsAdminActions;

    public const TIMEZONE = 'Europe/Istanbul';

    /** Semicolon and comma decimals: what a Turkish Excel opens as columns without an import wizard. */
    private const DELIMITER = ';';

    private const HEADER = [
        'reference', 'provider', 'status', 'amount', 'currency', 'paid_at', 'refunded_at', 'tag_serial',
        'billing_name', 'billing_address', 'billing_city', 'billing_national_id', 'flag_reason',
    ];

    /**
     * Both ends are inclusive instants, in any timezone; they are compared in UTC, which is how paid_at and refunded_at
     * are stored. A row appears once, with both dates, whenever its sale or its refund falls in the range.
     */
    public function toCsv(Carbon $from, Carbon $to, User $admin): StreamedResponse
    {
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();

        $query = Payment::query()
            ->with('tag:id,serial')
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
            // A sale in the range, or a refund made in the range for a sale from an earlier period (the credit note).
            ->where(fn ($q) => $q->whereBetween('paid_at', [$fromUtc, $toUtc])
                ->orWhere(fn ($q) => $q->where('status', PaymentStatus::Refunded->value)->whereBetween('refunded_at', [$fromUtc, $toUtc])));

        $this->audit($admin, 'payment.export', 'payment_export', $admin->id, 'sales export', [
            'from' => $from->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d'),
            'to' => $to->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d'),
            'rows' => (clone $query)->count(),
        ]);

        $name = 'wasla-sales-'.$from->copy()->setTimezone(self::TIMEZONE)->format('Ymd').'-'.$to->copy()->setTimezone(self::TIMEZONE)->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($query) {
            // A long range streams for a while; the PHP time limit must not cut the file off half way.
            set_time_limit(0);
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // so spreadsheets read the Turkish letters as UTF-8
            fputcsv($out, self::HEADER, self::DELIMITER, '"', '');

            foreach ($query->lazyById(500) as $payment) {
                fputcsv($out, array_map(self::cell(...), [
                    $payment->reference,
                    $payment->provider,
                    $payment->status->value,
                    str_replace('.', ',', Kurus::toDecimal($payment->amount_kurus)),
                    $payment->currency,
                    $payment->paid_at?->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
                    $payment->refunded_at?->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
                    $payment->tag?->serial,
                    $payment->billing_name,
                    $payment->billing_address,
                    $payment->billing_city,
                    $payment->billing_national_id,
                    $payment->flag_reason,
                ]), self::DELIMITER, '"', '');
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

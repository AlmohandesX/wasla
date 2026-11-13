<?php

namespace App\Services\Payments\Drivers;

use App\Enums\PaymentStatus;
use App\Services\Payments\CheckoutRequest;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\InvalidCallback;
use App\Services\Payments\Kurus;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayNotConfigured;
use App\Services\Payments\ProviderPaymentStatus;
use App\Services\Payments\VerifiedCallback;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayTR iFrame API (one-time hosted checkout).
 *
 * Docs read 2026-11-05:
 * - https://dev.paytr.com/en/iframe-api/iframe-api-1-adim (token request)
 * - https://dev.paytr.com/en/iframe-api/iframe-api-2-adim (callback)
 * - https://dev.paytr.com/en/durum-sorgu (status inquiry)
 *
 * Required buyer fields: email (ASCII, up to 100), user_ip, user_name (up to 60), user_address (up to 400),
 * user_phone (up to 20). PayTR takes no national ID, so none is sent.
 *
 * Signatures are all base64 of the raw HMAC-SHA256, keyed with the merchant key, of:
 * - token request: merchant_id user_ip merchant_oid email payment_amount user_basket no_installment max_installment
 *   currency test_mode merchant_salt
 * - callback: merchant_oid merchant_salt status total_amount
 * - status inquiry: merchant_id merchant_oid merchant_salt
 *
 * Sandbox: there is no separate URL. test_mode=1 on https://www.paytr.com runs a test transaction.
 *
 * The status inquiry answers err_no 004 for a payment with no success, including a failed one, so a failed payment
 * cannot be read from it. verifyCallback keeps the signed callback's status for fetchStatus: signed "failed" plus 004
 * is failed; signed "success" plus 004 is a retry, since PayTR repeats the callback until it gets OK.
 *
 * Could not confirm from the docs (all fail closed; see the live-sandbox step in the task report):
 * - That the checkout opens as a full page. The docs only show the URL as an iframe src, and we redirect the owner to it.
 * - The inquiry's amount format. The docs show both "10,8" and "0.76", so either separator is read as decimal lira.
 * - The shape of "returns" (documented as an array in one table and a string in another). Unrecognized values get a retry.
 * - Whether IPv6 is accepted: user_ip allows 39 characters but is labelled ipv4.
 * - The callback URL is set in the merchant panel, not per request, and no doc page read here describes that screen.
 */
class PaytrGateway implements PaymentGateway
{
    private const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';

    private const CHECKOUT_URL = 'https://www.paytr.com/odeme/guvenli/';

    private const STATUS_URL = 'https://www.paytr.com/odeme/durum-sorgu';

    private const NOT_FOUND = '004';

    /** The signed callback handled by this instance. A driver is built per request. */
    private ?string $callbackReference = null;

    private ?string $callbackStatus = null;

    public function name(): string
    {
        return 'paytr';
    }

    public function startCheckout(CheckoutRequest $request): CheckoutSession
    {
        [$merchantId, $key, $salt] = $this->credentials();

        // Altering an address could send a receipt to a stranger, so an unusable one stops here.
        if (! preg_match('/^[\x21-\x7e]{1,100}$/D', $request->buyerEmail)) {
            throw new RuntimeException('PayTR cannot take this email address.');
        }

        $amount = (string) $request->amountKurus;
        $basket = base64_encode(json_encode(
            [[$request->description, Kurus::toDecimal($request->amountKurus), 1]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        $currency = $request->currency === 'TRY' ? 'TL' : $request->currency;
        // No instalments, so the total paid stays the recorded amount.
        $noInstallment = '1';
        $maxInstallment = '0';
        $testMode = config('services.paytr.test_mode') ? '1' : '0';
        $returnUrl = rtrim((string) config('app.url'), '/').route('payments.return', [], false);

        $response = $this->client()->asForm()->post(self::TOKEN_URL, [
            'merchant_id' => $merchantId,
            'user_ip' => $request->buyerIp,
            'merchant_oid' => $request->merchantReference,
            'email' => $request->buyerEmail,
            'payment_amount' => $amount,
            'currency' => $currency,
            'user_basket' => $basket,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'paytr_token' => self::sign(
                $merchantId.$request->buyerIp.$request->merchantReference.$request->buyerEmail.$amount
                .$basket.$noInstallment.$maxInstallment.$currency.$testMode.$salt,
                $key,
            ),
            'user_name' => mb_substr($request->billing->name, 0, 60),
            'user_address' => mb_substr($request->billing->address.', '.$request->billing->city, 0, 400),
            'user_phone' => $request->billing->phone,
            'merchant_ok_url' => $returnUrl,
            'merchant_fail_url' => $returnUrl,
            'test_mode' => $testMode,
            'debug_on' => '0',
            'timeout_limit' => (string) max(1, (int) config('wasla.checkout_lifetime_minutes')),
            'lang' => $request->locale === 'en' ? 'en' : 'tr',
        ]);

        $data = $response->json();
        $token = $data['token'] ?? null;

        // A fixed message: the provider's "reason" could echo the buyer's details.
        if (($data['status'] ?? null) !== 'success' || ! is_string($token) || $token === '') {
            throw new RuntimeException('PayTR did not open a checkout.');
        }

        return new CheckoutSession(self::CHECKOUT_URL.rawurlencode($token), $request->merchantReference);
    }

    public function verifyCallback(Request $request): VerifiedCallback
    {
        [, $key, $salt] = $this->credentials();

        $reference = $request->input('merchant_oid');
        $status = $request->input('status');
        $total = $request->input('total_amount');
        $hash = $request->input('hash');

        if (! is_string($reference) || ! is_string($status) || ! is_string($total) || ! is_string($hash)
            || ! hash_equals(self::sign($reference.$salt.$status.$total, $key), $hash)
            || ! in_array($status, ['success', 'failed'], true)
            || ! preg_match('/^[A-Za-z0-9]{1,64}$/D', $reference)) {
            throw new InvalidCallback('Invalid PayTR callback.');
        }

        $this->callbackReference = $reference;
        $this->callbackStatus = $status;

        return new VerifiedCallback($reference);
    }

    public function fetchStatus(string $reference): ProviderPaymentStatus
    {
        [$merchantId, $key, $salt] = $this->credentials();

        $response = $this->client()->asForm()->post(self::STATUS_URL, [
            'merchant_id' => $merchantId,
            'merchant_oid' => $reference,
            'paytr_token' => self::sign($merchantId.$reference.$salt, $key),
        ]);

        $data = $response->serverError() ? null : Kurus::decodeJson($response->body(), ['payment_amount', 'payment_total', 'returns']);

        return match ($data['status'] ?? null) {
            'success' => $this->statusFromPayment($data),
            'error' => $this->statusFromError($data, $reference),
            default => throw new RuntimeException('PayTR did not answer the payment query.'),
        };
    }

    /** PayTR requires exactly this body, or it keeps retrying the callback. */
    public function callbackResponse(): Response
    {
        return new Response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function statusFromPayment(array $data): ProviderPaymentStatus
    {
        $currency = is_string($data['currency'] ?? null) ? $data['currency'] : '';
        $currency = $currency === 'TL' ? 'TRY' : $currency;

        if (self::hasRefund($data['returns'] ?? null)) {
            return new ProviderPaymentStatus(PaymentStatus::Refunded, 0, $currency);
        }

        // A live store can run test transactions, and those move no money.
        if ((string) (is_scalar($data['test_mode'] ?? null) ? $data['test_mode'] : '0') === '1' && ! config('services.paytr.test_mode')) {
            return new ProviderPaymentStatus(PaymentStatus::Pending, 0, $currency);
        }

        $kurus = Kurus::fromDecimal($data['payment_total'] ?? null)
            ?? throw new RuntimeException('PayTR reported an amount that could not be read exactly.');

        return new ProviderPaymentStatus(PaymentStatus::Paid, $kurus, $currency);
    }

    /** Records or a positive amount are a refund, and nothing or a zero amount is none. Anything else asks for a retry rather than a guess. */
    private static function hasRefund(mixed $returns): bool
    {
        if (empty($returns)) {
            return false;
        }

        if (is_array($returns)) {
            return true;
        }

        $kurus = Kurus::fromDecimal($returns)
            ?? throw new RuntimeException('PayTR reported refunds that could not be read.');

        return $kurus > 0;
    }

    private function statusFromError(array $data, string $reference): ProviderPaymentStatus
    {
        // The code alone, for diagnosis: err_msg is provider text.
        $code = is_scalar($data['err_no'] ?? null) ? (string) $data['err_no'] : '';

        if ($code !== self::NOT_FOUND) {
            Log::error('PayTR refused a payment query.', ['error_code' => ctype_alnum($code) ? $code : null]);

            throw new RuntimeException('PayTR did not answer the payment query.');
        }

        $signed = $this->callbackReference === $reference ? $this->callbackStatus : null;

        return match ($signed) {
            'failed' => new ProviderPaymentStatus(PaymentStatus::Failed, 0, 'TRY'),
            'success' => throw new RuntimeException('PayTR has not confirmed this payment yet.'),
            default => new ProviderPaymentStatus(PaymentStatus::Pending, 0, 'TRY'),
        };
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(20);
    }

    private static function sign(string $plain, string $key): string
    {
        return base64_encode(hash_hmac('sha256', $plain, $key, true));
    }

    /** @return array{string, string, string} */
    private function credentials(): array
    {
        $id = trim((string) config('services.paytr.merchant_id'));
        $key = trim((string) config('services.paytr.merchant_key'));
        $salt = trim((string) config('services.paytr.merchant_salt'));

        if ($id === '' || $key === '' || $salt === '') {
            throw new PaymentGatewayNotConfigured('The PayTR keys are not configured.');
        }

        return [$id, $key, $salt];
    }
}

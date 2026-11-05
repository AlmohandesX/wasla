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
 * iyzico Checkout Form (one-time hosted checkout), with Http and hand-written signing rather than the SDK.
 *
 * Docs read 2026-11-05:
 * - https://docs.iyzico.com/en/payment-methods/checkoutform/cf-implementation/cf-initialize.md
 * - https://docs.iyzico.com/en/payment-methods/checkoutform/cf-implementation/cf-retrieve.md
 * - https://docs.iyzico.com/en/payment-methods/checkoutform/cf-implementation/cf-sample-imp..md
 * - https://docs.iyzico.com/en/getting-started/preliminaries/authentication/hmacsha256-auth.md
 * - https://docs.iyzico.com/en/advanced/response-signature-validation
 * - https://docs.iyzico.com/en/advanced/webhook.md
 *
 * Required buyer fields: id, name, surname, identityNumber, email, gsmNumber, registrationAddress, city, country.
 * Also required: price, paidPrice, callbackUrl, billingAddress (address, contactName, city, country) and basketItems
 * (id, price, name, category1, itemType).
 *
 * Request signature: Authorization "IYZWSv2 base64(apiKey:KEY&randomKey:RND&signature:SIG)", where SIG is hex
 * HMAC-SHA256 of RND + URI path + raw body, keyed with the secret key. RND also goes in x-iyzi-rnd.
 * Response signature: hex HMAC-SHA256 of values joined with ":", keyed with the secret key, with trailing zeros
 * removed from prices. Initialize signs conversationId, token. Retrieve signs paymentStatus, paymentId, currency,
 * basketId, conversationId, paidPrice, price, token.
 *
 * Sandbox: https://sandbox-api.iyzipay.com (keys from https://sandbox-merchant.iyzipay.com). Live: https://api.iyzipay.com.
 *
 * How this fits the interface: the callback carries only a token and no signature, in the owner's browser POST and
 * in the optional webhook alike. So verifyCallback proves a token by a signed retrieve whose signed answer we check,
 * and the webhook's own X-IYZ-SIGNATURE-V3 is not needed. fetchStatus retrieves again and reads the status.
 *
 * Could not confirm from the docs (all fail closed; see the live-sandbox step in the task report):
 * - paymentStatus values. Only SUCCESS appears, in a sample; FAILURE is from memory, and anything else stays pending.
 * - Whether a failed payment's retrieve answers status=success with a signature. If not, it is refused as an invalid
 *   callback and the payment stays pending.
 * - How a null paymentId enters the signature. Treated as empty, as the docs' JavaScript join does.
 * - The sandbox API URL appears only inside a sample response, not in a stated reference.
 * - The docs conflict on shippingAddress (required by schema, not for VIRTUAL items by text). We send it.
 * - identityNumber: described as TCKN, with "passport number for foreign merchants" on some pages. No format for
 *   foreign buyers is documented, so v1 stays TC Kimlik only.
 */
class IyzicoGateway implements PaymentGateway
{
    private const INITIALIZE_PATH = '/payment/iyzipos/checkoutform/initialize/auth/ecom';

    private const RETRIEVE_PATH = '/payment/iyzipos/checkoutform/auth/ecom/detail';

    private const COUNTRY = 'Turkey';

    /** Amount keys in iyzico answers; see Kurus::decodeJson. */
    private const AMOUNT_KEYS = ['price', 'paidPrice'];

    public function name(): string
    {
        return 'iyzico';
    }

    public function startCheckout(CheckoutRequest $request): CheckoutSession
    {
        $billing = $request->billing;
        $parts = preg_split('/\s+/u', trim($billing->name));
        $surname = array_pop($parts);
        $amount = Kurus::toDecimal($request->amountKurus);
        $address = ['contactName' => $billing->name, 'city' => $billing->city, 'country' => self::COUNTRY, 'address' => $billing->address];

        if ($parts === []) {
            throw new RuntimeException('iyzico needs a name and a surname.');
        }

        $response = $this->post(self::INITIALIZE_PATH, [
            'locale' => $request->locale === 'en' ? 'en' : 'tr',
            'conversationId' => $request->merchantReference,
            'price' => $amount,
            'paidPrice' => $amount,
            'currency' => $request->currency,
            'basketId' => $request->merchantReference,
            'paymentGroup' => 'PRODUCT',
            // No instalments, so paidPrice stays the recorded amount.
            'enabledInstallments' => [1],
            // From APP_URL: a request's Host header must not decide where iyzico reports payments.
            'callbackUrl' => rtrim((string) config('app.url'), '/').route('payments.callback', ['provider' => 'iyzico'], false),
            'buyer' => [
                'id' => $request->buyerId,
                'name' => implode(' ', $parts),
                'surname' => $surname,
                'identityNumber' => $billing->nationalId,
                'email' => $request->buyerEmail,
                'gsmNumber' => $billing->phone,
                'registrationAddress' => $billing->address,
                'city' => $billing->city,
                'country' => self::COUNTRY,
                'ip' => $request->buyerIp,
            ],
            'billingAddress' => $address,
            'shippingAddress' => $address,
            'basketItems' => [[
                'id' => $request->merchantReference,
                'name' => $request->description,
                'category1' => 'Subscription',
                'itemType' => 'VIRTUAL',
                'price' => $amount,
            ]],
        ]);

        $data = Kurus::decodeJson($response->body(), self::AMOUNT_KEYS);
        $token = $data['token'] ?? null;
        $url = $data['paymentPageUrl'] ?? null;

        // Fixed messages only: a provider's text could echo the buyer's details.
        if (($data['status'] ?? null) !== 'success' || ! self::usableToken($token) || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('iyzico did not open a checkout.');
        }

        if (($data['conversationId'] ?? null) !== $request->merchantReference
            || ! $this->signatureMatches($data['signature'] ?? null, [$request->merchantReference, $token])) {
            throw new RuntimeException('iyzico checkout answer failed its signature check.');
        }

        return new CheckoutSession($url, $token);
    }

    public function verifyCallback(Request $request): VerifiedCallback
    {
        $token = $request->input('token');
        $this->credentials();

        if (! self::usableToken($token)) {
            throw new InvalidCallback('Invalid iyzico callback.');
        }

        $this->retrieve($token);

        return new VerifiedCallback($token);
    }

    public function fetchStatus(string $reference): ProviderPaymentStatus
    {
        $data = $this->retrieve($reference);
        $paymentStatus = $data['paymentStatus'] ?? null;
        // Not covered by the response signature; it comes over TLS from iyzico. Missing means not approved.
        $fraud = is_scalar($data['fraudStatus'] ?? null) ? (string) $data['fraudStatus'] : null;
        $currency = is_string($data['currency'] ?? null) ? $data['currency'] : '';

        if ($paymentStatus === 'FAILURE' || $fraud === '-1') {
            return new ProviderPaymentStatus(PaymentStatus::Failed, 0, $currency);
        }

        if ($paymentStatus !== 'SUCCESS' || $fraud !== '1') {
            return new ProviderPaymentStatus(PaymentStatus::Pending, 0, $currency);
        }

        $kurus = Kurus::fromDecimal($data['paidPrice'] ?? null)
            ?? throw new RuntimeException('iyzico reported an amount that is not whole kurus.');

        return new ProviderPaymentStatus(PaymentStatus::Paid, $kurus, $currency);
    }

    /**
     * The owner's browser lands on this POST, and the webhook needs a 2xx on the same URL, so a redirect will not do:
     * a 200 that moves the browser on to the return page.
     */
    public function callbackResponse(): Response
    {
        return new Response(view('payments.return', ['refreshTo' => $this->returnUrl()])->render(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @throws InvalidCallback when iyzico refuses the token or its answer fails the signature check
     * @throws RuntimeException when iyzico cannot answer, so the caller can answer 503 and a retry can succeed
     */
    private function retrieve(string $token): array
    {
        $response = $this->post(self::RETRIEVE_PATH, ['locale' => 'tr', 'token' => $token]);
        $data = $response->serverError() ? null : Kurus::decodeJson($response->body(), self::AMOUNT_KEYS);

        if ($data === null) {
            throw new RuntimeException('iyzico did not answer the payment query.');
        }

        if (($data['status'] ?? null) !== 'success') {
            // The code alone, for diagnosis: errorMessage could echo buyer data.
            $code = $data['errorCode'] ?? null;
            Log::warning('iyzico refused a payment query.', ['error_code' => is_string($code) && ctype_alnum($code) ? $code : null]);

            throw new InvalidCallback('Invalid iyzico callback.');
        }

        if (($data['token'] ?? null) !== $token || ! $this->signatureMatches($data['signature'] ?? null, [
            $this->signedValue($data, 'paymentStatus'),
            $this->signedValue($data, 'paymentId'),
            $this->signedValue($data, 'currency'),
            $this->signedValue($data, 'basketId'),
            $this->signedValue($data, 'conversationId'),
            self::withoutTrailingZeros($this->signedValue($data, 'paidPrice')),
            self::withoutTrailingZeros($this->signedValue($data, 'price')),
            $token,
        ])) {
            throw new InvalidCallback('Invalid iyzico callback.');
        }

        return $data;
    }

    private function post(string $path, array $payload): \Illuminate\Http\Client\Response
    {
        [$apiKey, $secret] = $this->credentials();
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $random = now()->getTimestampMs().bin2hex(random_bytes(8));
        $signature = hash_hmac('sha256', $random.$path.$body, $secret);
        $authorization = base64_encode('apiKey:'.$apiKey.'&randomKey:'.$random.'&signature:'.$signature);

        return $this->client()
            ->withHeaders(['Authorization' => 'IYZWSv2 '.$authorization, 'x-iyzi-rnd' => $random])
            ->withBody($body, 'application/json')
            ->post(rtrim((string) config('services.iyzico.base_url'), '/').$path);
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(20);
    }

    private function signatureMatches(mixed $signature, array $values): bool
    {
        [, $secret] = $this->credentials();

        return is_string($signature) && hash_equals(hash_hmac('sha256', implode(':', $values), $secret), $signature);
    }

    private function signedValue(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : throw new InvalidCallback('Invalid iyzico callback.');
    }

    private static function withoutTrailingZeros(string $price): string
    {
        return str_contains($price, '.') ? rtrim(rtrim($price, '0'), '.') : $price;
    }

    /** The token becomes our payment reference, a 100 character column. */
    private static function usableToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $token) === 1;
    }

    private function returnUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('payments.return', [], false);
    }

    /** @return array{string, string} */
    private function credentials(): array
    {
        $apiKey = trim((string) config('services.iyzico.api_key'));
        $secret = trim((string) config('services.iyzico.secret_key'));

        if ($apiKey === '' || $secret === '') {
            throw new PaymentGatewayNotConfigured('The iyzico keys are not configured.');
        }

        return [$apiKey, $secret];
    }
}

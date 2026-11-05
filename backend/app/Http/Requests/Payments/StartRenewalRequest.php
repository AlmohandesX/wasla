<?php

namespace App\Http\Requests\Payments;

use App\Rules\TcKimlik;
use App\Services\Payments\BillingDetails;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StartRenewalRequest extends FormRequest
{
    /** Never flashed back to the client, even if this request were to redirect. */
    protected $dontFlash = ['billing_national_id', 'billing_phone'];

    public function authorize(): bool
    {
        $car = $this->route('car');

        return $car !== null && $this->user()->can('renew', $car);
    }

    public function rules(): array
    {
        return [
            // iyzico requires a surname as well as a name.
            'billing_name' => ['bail', 'required', 'string', 'max:120', function (string $attribute, mixed $value, Closure $fail) {
                if (! preg_match('/\S\s+\S/u', trim($value))) {
                    $fail('wasla.billing_full_name_required')->translate();
                }
            }],
            'billing_address' => ['required', 'string', 'max:300'],
            'billing_city' => ['required', 'string', 'max:80'],
            // v1 accepts a Turkish TC Kimlik only; foreign buyers are revisited with the provider docs.
            'billing_national_id' => ['required', 'string', new TcKimlik],
            // Both providers require it. It is passed on and never stored.
            'billing_phone' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (self::normalizePhone($value) === null) {
                    $fail('wasla.invalid_billing_phone')->translate();
                }
            }],
            'legal_accepted' => ['required', 'accepted'],
        ];
    }

    /** International form: a Turkish mobile number in any common spelling, or a number with its own country code. */
    private static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/[\s()-]/', '', $phone);

        return match (true) {
            preg_match('/^(?:\+?90|0)?(5\d{9})$/D', $digits, $m) === 1 => '+90'.$m[1],
            preg_match('/^\+[1-9]\d{7,14}$/D', $digits) === 1 && ! str_starts_with($digits, '+90') => $digits,
            default => null,
        };
    }

    public function billing(): BillingDetails
    {
        $data = $this->validated();

        return new BillingDetails(
            trim($data['billing_name']),
            trim($data['billing_address']),
            trim($data['billing_city']),
            $data['billing_national_id'],
            self::normalizePhone($data['billing_phone']),
        );
    }
}

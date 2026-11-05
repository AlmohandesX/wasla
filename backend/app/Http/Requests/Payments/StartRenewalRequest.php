<?php

namespace App\Http\Requests\Payments;

use App\Rules\TcKimlik;
use App\Services\Payments\BillingDetails;
use Illuminate\Foundation\Http\FormRequest;

class StartRenewalRequest extends FormRequest
{
    /** Never flashed back to the client, even if this request were to redirect. */
    protected $dontFlash = ['billing_national_id'];

    public function authorize(): bool
    {
        $car = $this->route('car');

        return $car !== null && $this->user()->can('renew', $car);
    }

    public function rules(): array
    {
        return [
            'billing_name' => ['required', 'string', 'max:120'],
            'billing_address' => ['required', 'string', 'max:300'],
            'billing_city' => ['required', 'string', 'max:80'],
            // v1 accepts a Turkish TC Kimlik only; foreign buyers are revisited with the provider docs.
            'billing_national_id' => ['required', 'string', new TcKimlik],
            'legal_accepted' => ['required', 'accepted'],
        ];
    }

    public function billing(): BillingDetails
    {
        $data = $this->validated();

        return new BillingDetails(
            trim($data['billing_name']),
            trim($data['billing_address']),
            trim($data['billing_city']),
            $data['billing_national_id'],
        );
    }
}

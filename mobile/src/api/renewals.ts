import { apiRequest } from './client';

export type PaymentStatus = 'pending' | 'paid' | 'failed' | 'refunded';

/** What the server says about a payment: status only, no billing data. */
export type Payment = {
  id: number;
  status: PaymentStatus;
  amount_kurus: number;
  currency: string;
  paid_at: string | null;
};

export type BillingInput = {
  billing_name: string;
  billing_address: string;
  billing_city: string;
  billing_phone: string;
  /** Sent once in the request body and never kept anywhere by the app. */
  billing_national_id: string;
  legal_accepted: boolean;
};

export type StartedRenewal = Payment & { redirect_url: string; checkout_expires_at: string };

export async function startRenewal(carId: number, billing: BillingInput): Promise<StartedRenewal> {
  const response = await apiRequest<{ data: StartedRenewal }>(`cars/${carId}/renewals`, {
    method: 'POST',
    body: billing,
    timeoutMs: 30000,
  });
  return response.data;
}

export async function getPayment(paymentId: number, timeoutMs?: number): Promise<Payment> {
  return (await apiRequest<{ data: Payment }>(`renewals/${paymentId}`, { timeoutMs })).data;
}

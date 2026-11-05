/**
 * The checkout in progress for each car, kept in module memory only so leaving and reopening the renewal screen
 * does not offer a second one. Holds a payment id, the hosted checkout address and when the backend says the
 * checkout stops being payable: never billing data, never the national ID, never written to storage.
 */
export type PendingRenewal = {
  /** Null when the open checkout belongs to someone else (the server does not say whose it is). */
  paymentId: number | null;
  checkoutUrl: string | null;
  /** Epoch ms from the server's checkout_expires_at. After it the checkout can no longer be paid. */
  expiresAt: number;
};

const inProgress = new Map<number, PendingRenewal>();

export function parseExpiry(iso: string | null | undefined): number {
  const ms = iso ? Date.parse(iso) : NaN;
  return Number.isNaN(ms) ? 0 : ms; // unknown counts as already over, so it can never trap the owner
}

export function rememberPending(carId: number, entry: PendingRenewal): void {
  inProgress.set(carId, entry);
}

export function pendingFor(carId: number): PendingRenewal | null {
  return inProgress.get(carId) ?? null;
}

export function isOver(entry: PendingRenewal, now: number = Date.now()): boolean {
  return now >= entry.expiresAt;
}

export function forgetPending(carId: number): void {
  inProgress.delete(carId);
}

/** Sign-out, account deletion and a change of user: nothing from one account may reach the next. */
export function clearAllPending(): void {
  inProgress.clear();
}

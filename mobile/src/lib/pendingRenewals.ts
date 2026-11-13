/**
 * The checkout in progress for each car, kept in module memory only so leaving and reopening the renewal screen
 * does not offer a second one. Holds a payment id, the hosted checkout address and when the backend says the
 * checkout stops being payable: never billing data, never the national ID, never written to storage.
 */
export type PendingRenewal = {
  /** Null when the open checkout belongs to someone else (the server does not say whose it is). */
  paymentId: number | null;
  checkoutUrl: string | null;
  /** Phone-clock epoch ms at which the checkout stops being payable: the moment the answer arrived plus the
   * seconds the server said were left, so a wrong phone clock does not matter. */
  expiresAt: number;
};

const inProgress = new Map<number, PendingRenewal>();

/** Call when the server's answer arrives. Unknown counts as already over, so it can never trap the owner. */
export function expiryFromSeconds(secondsLeft: number | null | undefined): number {
  return typeof secondsLeft === 'number' && Number.isFinite(secondsLeft) ? Date.now() + Math.max(0, secondsLeft) * 1000 : 0;
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

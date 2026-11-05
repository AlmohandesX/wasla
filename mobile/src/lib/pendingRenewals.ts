/**
 * The payment in progress for each car, kept in module memory only so leaving and reopening the renewal
 * screen does not offer a second checkout. Holds a payment id and nothing else: never billing data, never the
 * national ID, never written to storage.
 */
const inProgress = new Map<number, number>();

export function rememberPending(carId: number, paymentId: number): void {
  inProgress.set(carId, paymentId);
}

export function pendingFor(carId: number): number | null {
  return inProgress.get(carId) ?? null;
}

export function forgetPending(carId: number): void {
  inProgress.delete(carId);
}

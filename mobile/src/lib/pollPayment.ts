import { ApiError } from '../api/errors';
import { getPayment } from '../api/renewals';

export type PollResult = 'paid' | 'failed' | 'pending';

export type PollOptions = {
  intervalMs: number;
  timeoutMs: number;
  /** Stops the polling (screen left). The result is then 'pending': nothing was learned. */
  signal?: AbortSignal;
};

const REQUEST_TIMEOUT_MS = 8000;

/** Failures that say nothing about the payment: the next poll may well work. Rethrown otherwise. */
function isTemporary(error: unknown): boolean {
  // Anything that is not an ApiError is a bug in the app, not a network hiccup: do not hide it.
  if (!(error instanceof ApiError)) return false;
  return error.code === 'network_error' || error.code === 'invalid_response' || error.status === 429 || error.status >= 500;
}

function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve) => {
    if (signal?.aborted) return resolve();
    const done = () => {
      clearTimeout(timer);
      signal?.removeEventListener('abort', done);
      resolve();
    };
    const timer = setTimeout(done, ms);
    signal?.addEventListener('abort', done);
  });
}

/**
 * Asks the backend, and only the backend, whether a payment went through. One request at a time. A network
 * error or server hiccup does not end it; running out of time returns 'pending' (still being confirmed),
 * never 'failed'. An error that is certain (not found, forbidden, signed out) is thrown for the screen to show.
 */
export async function pollPayment(paymentId: number, options: PollOptions): Promise<PollResult> {
  const deadline = Date.now() + options.timeoutMs;
  while (!options.signal?.aborted) {
    try {
      const payment = await getPayment(paymentId, REQUEST_TIMEOUT_MS);
      if (options.signal?.aborted) return 'pending';
      if (payment.status === 'paid') return 'paid';
      if (payment.status === 'failed' || payment.status === 'refunded') return 'failed';
    } catch (error) {
      if (!isTemporary(error)) throw error;
    }
    if (Date.now() + options.intervalMs >= deadline) break;
    await sleep(options.intervalMs, options.signal);
  }
  return 'pending';
}

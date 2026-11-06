import { ApiError } from '../api/errors';

export const POLL_MS = 5000;
const MAX_BACKOFF_MS = 60000;
const THROTTLED_DEFAULT_MS = 30000;

/** Delay before the next poll: 5 s when healthy, doubling per consecutive failure, Retry-After on 429. */
export function nextPollDelay(failures: number, error: ApiError | null): number {
  if (error?.status === 429) {
    return error.retryAfter ? Math.max(POLL_MS, error.retryAfter * 1000) : THROTTLED_DEFAULT_MS;
  }
  if (failures <= 0) return POLL_MS;
  return Math.min(MAX_BACKOFF_MS, POLL_MS * 2 ** failures);
}

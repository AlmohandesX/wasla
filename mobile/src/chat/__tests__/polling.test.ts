import { ApiError } from '../../api/errors';
import { nextPollDelay, POLL_MS } from '../polling';

const err = (status: number, retryAfter?: number) => new ApiError(status, 'x', 'x', { retryAfter });

test('polls every 5 seconds while healthy', () => {
  expect(nextPollDelay(0, null)).toBe(POLL_MS);
  expect(POLL_MS).toBe(5000);
});

test('backs off on repeated errors up to a cap', () => {
  expect(nextPollDelay(1, err(0))).toBe(10000);
  expect(nextPollDelay(2, err(500))).toBe(20000);
  expect(nextPollDelay(10, err(500))).toBe(60000);
});

test('honours Retry-After on 429', () => {
  expect(nextPollDelay(1, err(429, 30))).toBe(30000);
  expect(nextPollDelay(1, err(429, 1))).toBe(POLL_MS);
  expect(nextPollDelay(1, err(429))).toBe(30000);
});

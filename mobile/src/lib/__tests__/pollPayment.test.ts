import { ApiError } from '../../api/errors';
import type { Payment } from '../../api/renewals';
import { pollPayment } from '../pollPayment';

jest.mock('../../api/renewals', () => ({ getPayment: jest.fn() }));
const { getPayment } = jest.requireMock('../../api/renewals') as { getPayment: jest.Mock };

const payment = (status: Payment['status']): Payment => ({ id: 7, status, amount_kurus: 10000, currency: 'TRY', paid_at: null });
const networkDown = () => new ApiError(0, 'network_error', 'offline');

beforeEach(() => {
  jest.useFakeTimers();
  getPayment.mockReset();
});
afterEach(() => jest.useRealTimers());

test('resolves paid as soon as the backend says so', async () => {
  getPayment.mockResolvedValueOnce(payment('pending')).mockResolvedValueOnce(payment('paid'));
  const result = pollPayment(7, { intervalMs: 1000, timeoutMs: 60000 });
  await jest.advanceTimersByTimeAsync(1000);
  await expect(result).resolves.toBe('paid');
  expect(getPayment).toHaveBeenCalledTimes(2);
});

test('resolves failed on failure', async () => {
  getPayment.mockResolvedValue(payment('failed'));
  await expect(pollPayment(7, { intervalMs: 1000, timeoutMs: 60000 })).resolves.toBe('failed');
});

test('resolves pending at the timeout, without treating it as a failure', async () => {
  getPayment.mockResolvedValue(payment('pending'));
  const result = pollPayment(7, { intervalMs: 1000, timeoutMs: 3500 });
  await jest.advanceTimersByTimeAsync(5000);
  await expect(result).resolves.toBe('pending');
});

test('keeps polling through a temporary network error', async () => {
  getPayment.mockRejectedValueOnce(networkDown()).mockRejectedValueOnce(networkDown()).mockResolvedValueOnce(payment('paid'));
  const result = pollPayment(7, { intervalMs: 1000, timeoutMs: 60000 });
  await jest.advanceTimersByTimeAsync(2000);
  await expect(result).resolves.toBe('paid');
});

test('a timeout while the network is down is pending, not failed', async () => {
  getPayment.mockRejectedValue(networkDown());
  const result = pollPayment(7, { intervalMs: 1000, timeoutMs: 2500 });
  await jest.advanceTimersByTimeAsync(4000);
  await expect(result).resolves.toBe('pending');
});

test('stops when aborted and makes no further request', async () => {
  getPayment.mockResolvedValue(payment('pending'));
  const controller = new AbortController();
  const result = pollPayment(7, { intervalMs: 1000, timeoutMs: 60000, signal: controller.signal });
  await jest.advanceTimersByTimeAsync(0);
  controller.abort();
  await expect(result).resolves.toBe('pending');
  const calls = getPayment.mock.calls.length;
  await jest.advanceTimersByTimeAsync(10000);
  expect(getPayment).toHaveBeenCalledTimes(calls);
});

test('never overlaps requests', async () => {
  let inFlight = 0;
  let maxInFlight = 0;
  getPayment.mockImplementation(async () => {
    inFlight += 1;
    maxInFlight = Math.max(maxInFlight, inFlight);
    await new Promise((r) => setTimeout(r, 3000));
    inFlight -= 1;
    return payment('pending');
  });
  const result = pollPayment(7, { intervalMs: 500, timeoutMs: 12000 });
  await jest.advanceTimersByTimeAsync(20000);
  await result;
  expect(maxInFlight).toBe(1);
});

test('an error that is certain is thrown, not swallowed', async () => {
  getPayment.mockRejectedValue(new ApiError(404, 'not_found', 'nope'));
  await expect(pollPayment(7, { intervalMs: 1000, timeoutMs: 60000 })).rejects.toThrow('nope');
});

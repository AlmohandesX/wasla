import { ApiError } from '../../api/errors';
import type { Message } from '../../lib/mergeMessages';
import { createPoller } from '../poller';

const msg = (id: number): Message => ({
  id, thread_id: 1, sender: 'visitor', body: `m${id}`, read: false, reported: false, created_at: '2026-11-05T10:00:00+00:00',
});

function setup(load: (after: number) => Promise<Message[]>) {
  const onMessages = jest.fn();
  const onSuccess = jest.fn();
  const onError = jest.fn();
  const onFatal = jest.fn();
  const poller = createPoller({ load, onMessages, onSuccess, onError, onFatal });
  return { poller, onMessages, onSuccess, onError, onFatal };
}

beforeEach(() => jest.useFakeTimers());
afterEach(() => jest.useRealTimers());

test('polls every 5 seconds and asks only for messages after the last one it saw', async () => {
  const load = jest.fn().mockResolvedValueOnce([msg(1), msg(2)]).mockResolvedValue([]);
  const { poller } = setup(load);
  poller.start();
  await jest.advanceTimersByTimeAsync(0);
  expect(load).toHaveBeenLastCalledWith(0);
  await jest.advanceTimersByTimeAsync(5000);
  expect(load).toHaveBeenLastCalledWith(2);
  expect(load).toHaveBeenCalledTimes(2);
  poller.stop();
});

test('a message the owner sends during a poll does not move the cursor past a visitor message', async () => {
  // The poll that is in flight will return visitor message 9; the sent message (10) is merged by the screen
  // but the poller never sees it, so the next poll still asks for everything after 8.
  let release: (m: Message[]) => void = () => undefined;
  const load = jest.fn().mockResolvedValueOnce([msg(8)]).mockImplementationOnce(() => new Promise<Message[]>((r) => (release = r)));
  const { poller } = setup(load);
  poller.start();
  await jest.advanceTimersByTimeAsync(5000); // second poll now in flight
  release([msg(9)]);
  await jest.advanceTimersByTimeAsync(0);
  load.mockResolvedValue([]);
  await jest.advanceTimersByTimeAsync(5000);
  expect(load).toHaveBeenLastCalledWith(9);
  poller.stop();
});

test('never overlaps requests', async () => {
  let release: (m: Message[]) => void = () => undefined;
  const load = jest.fn(() => new Promise<Message[]>((r) => (release = r)));
  const { poller } = setup(load);
  poller.start();
  await jest.advanceTimersByTimeAsync(0);
  void poller.pollNow();
  await jest.advanceTimersByTimeAsync(20000);
  expect(load).toHaveBeenCalledTimes(1);
  release([]);
  poller.stop();
});

test('stops on blur or background and does not poll again', async () => {
  const load = jest.fn().mockResolvedValue([]);
  const { poller } = setup(load);
  poller.start();
  await jest.advanceTimersByTimeAsync(0);
  poller.stop();
  await jest.advanceTimersByTimeAsync(30000);
  expect(load).toHaveBeenCalledTimes(1);
  poller.start();
  await jest.advanceTimersByTimeAsync(0);
  expect(load).toHaveBeenCalledTimes(2);
  poller.stop();
});

test('backs off on errors and honours Retry-After on 429', async () => {
  const load = jest
    .fn()
    .mockRejectedValueOnce(new ApiError(500, 'server_error', 'x'))
    .mockRejectedValueOnce(new ApiError(429, 'too_many_requests', 'x', { retryAfter: 40 }))
    .mockResolvedValue([]);
  const { poller, onError } = setup(load);
  poller.start();
  await jest.advanceTimersByTimeAsync(0);
  expect(onError).toHaveBeenCalledTimes(1);
  await jest.advanceTimersByTimeAsync(9999);
  expect(load).toHaveBeenCalledTimes(1); // 10 s after the first failure
  await jest.advanceTimersByTimeAsync(1);
  expect(load).toHaveBeenCalledTimes(2);
  await jest.advanceTimersByTimeAsync(39999);
  expect(load).toHaveBeenCalledTimes(2); // Retry-After 40 s
  await jest.advanceTimersByTimeAsync(1);
  expect(load).toHaveBeenCalledTimes(3);
  poller.stop();
});

test('a 402, 403 or 404 stops polling and reports it as fatal', async () => {
  const load = jest.fn().mockRejectedValue(new ApiError(404, 'not_found', 'x'));
  const { poller, onFatal } = setup(load);
  poller.start();
  await jest.advanceTimersByTimeAsync(0);
  expect(onFatal).toHaveBeenCalledTimes(1);
  await jest.advanceTimersByTimeAsync(60000);
  expect(load).toHaveBeenCalledTimes(1);
});

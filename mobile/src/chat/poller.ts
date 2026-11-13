import { toApiError, type ApiError } from '../api/errors';
import type { Message } from '../lib/mergeMessages';
import { nextPollDelay } from './polling';

/** Codes after which another poll cannot succeed (expired tag, not this owner's thread, no such thread). */
export const FATAL_STATUSES = [402, 403, 404];

type Options = {
  load: (after: number) => Promise<Message[]>;
  onMessages: (messages: Message[]) => void;
  onSuccess: () => void;
  onError: (error: ApiError) => void;
  onFatal: (error: ApiError) => void;
};

/**
 * The polling loop, with no React in it. One request at a time. The cursor (`after`) moves only on what a poll
 * itself returned, never on a message the owner sent, so a visitor message that landed before a sent one is
 * still fetched. Backs off on failure; a 429 waits for Retry-After; a fatal status stops the loop.
 */
export function createPoller(o: Options) {
  let cursor = 0;
  let running = false;
  let epoch = 0; // bumped by every start and stop, so a loop from an earlier run can tell it is stale
  let current: Promise<void> | null = null;
  let failures = 0;
  let lastError: ApiError | null = null;
  let timer: ReturnType<typeof setTimeout> | undefined;

  /** The callbacks belong to the screen: a bug in one is not a failed poll and must not cause a back-off. */
  function safely(call: () => void) {
    try {
      call();
    } catch {
      // ignored on purpose
    }
  }

  async function run(): Promise<void> {
    let messages: Message[];
    try {
      messages = await o.load(cursor);
    } catch (e) {
      const error = toApiError(e);
      failures += 1;
      lastError = error;
      if (FATAL_STATUSES.includes(error.status)) {
        running = false;
        safely(() => o.onFatal(error));
      } else {
        safely(() => o.onError(error));
      }
      return;
    }
    for (const m of messages) cursor = Math.max(cursor, m.id);
    failures = 0;
    lastError = null;
    safely(() => o.onMessages(messages));
    safely(() => o.onSuccess());
  }

  /** One poll, never two at once. Called during a poll, it waits for it and then polls again, so the data is fresh. */
  async function pollNow(): Promise<void> {
    while (current) await current;
    current = run().finally(() => {
      current = null;
    });
    await current;
  }

  async function tick(myEpoch: number) {
    await pollNow();
    if (running && myEpoch === epoch) timer = setTimeout(() => void tick(myEpoch), nextPollDelay(failures, lastError));
  }

  return {
    start() {
      if (running) return;
      running = true;
      epoch += 1;
      void tick(epoch);
    },
    stop() {
      running = false;
      epoch += 1;
      if (timer) clearTimeout(timer);
      timer = undefined;
    },
    pollNow,
  };
}

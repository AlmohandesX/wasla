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
  let inFlight = false;
  let failures = 0;
  let lastError: ApiError | null = null;
  let timer: ReturnType<typeof setTimeout> | undefined;

  async function pollNow(): Promise<void> {
    if (inFlight) return;
    inFlight = true;
    try {
      const messages = await o.load(cursor);
      for (const m of messages) cursor = Math.max(cursor, m.id);
      failures = 0;
      lastError = null;
      o.onMessages(messages);
      o.onSuccess();
    } catch (e) {
      const error = toApiError(e);
      failures += 1;
      lastError = error;
      if (FATAL_STATUSES.includes(error.status)) {
        running = false;
        o.onFatal(error);
      } else {
        o.onError(error);
      }
    } finally {
      inFlight = false;
    }
  }

  async function tick() {
    await pollNow();
    if (running) timer = setTimeout(tick, nextPollDelay(failures, lastError));
  }

  return {
    start() {
      if (running) return;
      running = true;
      void tick();
    },
    stop() {
      running = false;
      if (timer) clearTimeout(timer);
      timer = undefined;
    },
    pollNow,
  };
}

import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';

import { getCar, type Car } from '../api/cars';
import { blockThread, listMessages, listThreads, reportMessage, sendMessage, type Message, type Thread } from '../api/chats';
import { ApiError, toApiError } from '../api/errors';
import { mergeMessages } from '../lib/mergeMessages';
import { nextPollDelay } from './polling';

export type Pending = { key: number; body: string; status: 'sending' | 'failed'; error?: string };

/** Codes after which polling cannot succeed, so it stops instead of backing off. */
const FATAL = new Set([402, 403, 404]);

let pendingKey = 0;

export function useThread(threadId: number) {
  const [thread, setThread] = useState<Thread | null>(null);
  const [car, setCar] = useState<Car | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [pending, setPending] = useState<Pending[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [fatal, setFatal] = useState<ApiError | null>(null);
  const [pollFailing, setPollFailing] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const [focused, setFocused] = useState(false);
  const [foreground, setForeground] = useState(AppState.currentState === 'active');

  const lastId = useRef(0);
  const inFlight = useRef(false); // one request at a time between polls and sends
  const failures = useRef(0);
  const lastError = useRef<ApiError | null>(null);

  useFocusEffect(
    useCallback(() => {
      setFocused(true);
      return () => setFocused(false);
    }, []),
  );

  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => setForeground(state === 'active'));
    return () => sub.remove();
  }, []);

  const merge = useCallback((incoming: Message[]) => {
    setMessages((current) => {
      const next = mergeMessages(current, incoming);
      lastId.current = next.length ? next[next.length - 1].id : 0;
      return next;
    });
  }, []);

  /** The thread's state and its car: whether replying can work at all. */
  const loadMeta = useCallback(async () => {
    const threads = await listThreads();
    const found = threads.find((t) => t.id === threadId);
    if (!found) throw new ApiError(404, 'not_found', '');
    setThread(found);
    setCar(await getCar(found.car_id));
  }, [threadId]);

  const poll = useCallback(async () => {
    if (inFlight.current) return;
    inFlight.current = true;
    try {
      merge(await listMessages(threadId, lastId.current));
      failures.current = 0;
      lastError.current = null;
      setPollFailing(false);
    } catch (e) {
      const error = toApiError(e);
      if (FATAL.has(error.status)) setFatal(error);
      failures.current += 1;
      lastError.current = error;
      setPollFailing(true);
    } finally {
      inFlight.current = false;
      setLoaded(true);
    }
  }, [threadId, merge]);

  // Poll only while the thread is on screen and the app is in the foreground; one timer, no overlap.
  useEffect(() => {
    if (!focused || !foreground || fatal) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const tick = async () => {
      await poll();
      if (!cancelled) timer = setTimeout(tick, nextPollDelay(failures.current, lastError.current));
    };
    void tick();
    void loadMeta().catch((e) => {
      const error = toApiError(e);
      if (FATAL.has(error.status)) setFatal(error);
    });
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [focused, foreground, fatal, poll, loadMeta]);

  /** Sends `body`. Resolves true when it was accepted, so the caller can clear its input. */
  const send = useCallback(
    async (body: string): Promise<boolean> => {
      if (inFlight.current) return false;
      inFlight.current = true;
      const key = ++pendingKey;
      setSendError(null);
      setPending((list) => [...list.filter((p) => p.status !== 'failed'), { key, body, status: 'sending' }]);
      try {
        const sent = await sendMessage(threadId, body);
        merge([sent]);
        setPending((list) => list.filter((p) => p.key !== key));
        return true;
      } catch (e) {
        const error = toApiError(e);
        if (error.status === 402) {
          setFatal(error);
          setPending((list) => list.filter((p) => p.key !== key));
        } else if (error.status === 403 || error.status === 422) {
          // Refused for a reason retrying cannot change: the text stays in the input for the owner to edit.
          setSendError(error.message);
          setPending((list) => list.filter((p) => p.key !== key));
          if (error.status === 403) void loadMeta().catch(() => undefined); // the thread or tag changed: refresh what disables the composer
        } else {
          setPending((list) => list.map((p) => (p.key === key ? { ...p, status: 'failed', error: error.message } : p)));
        }
        return false;
      } finally {
        inFlight.current = false;
      }
    },
    [threadId, merge, loadMeta],
  );

  const discard = useCallback((key: number) => setPending((list) => list.filter((p) => p.key !== key)), []);

  const block = useCallback(async () => {
    setActionError(null);
    try {
      setThread(await blockThread(threadId));
    } catch (e) {
      setActionError(toApiError(e).message);
      return false;
    }
    return true;
  }, [threadId]);

  const report = useCallback(
    async (messageId: number) => {
      setActionError(null);
      try {
        merge([await reportMessage(messageId)]);
      } catch (e) {
        setActionError(toApiError(e).message);
      }
    },
    [merge],
  );

  return { thread, car, messages, pending, loaded, fatal, pollFailing, sendError, setSendError, actionError, send, discard, block, report };
}

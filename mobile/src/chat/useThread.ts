import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AccessibilityInfo, AppState } from 'react-native';

import { blockThread, getThread, listMessages, reportMessage, sendMessage, type ThreadDetail } from '../api/chats';
import { ApiError, toApiError } from '../api/errors';
import { t } from '../i18n';
import { mergeMessages, type Message } from '../lib/mergeMessages';
import { createPoller } from './poller';

export type Pending = {
  key: number;
  body: string;
  status: 'sending' | 'failed';
  error?: string;
  /** A timed-out send may have arrived: Retry waits until a poll has checked. */
  checking?: boolean;
};

/** The thread and its car are re-read at most this often while the screen stays open. */
const META_FRESH_MS = 30000;

let pendingKey = 0;

/** What a 403 from sending means in plain words: chat_unavailable has its own text, anything else is generic. */
function sendRefusal(error: ApiError): string {
  return error.status === 403 && error.code !== 'chat_unavailable' ? t('errors.forbidden') : error.message;
}

export function useThread(threadId: number, initialBlocked = false) {
  const [meta, setMeta] = useState<ThreadDetail | null>(null);
  const [metaFailed, setMetaFailed] = useState(false);
  const [blocked, setBlocked] = useState(initialBlocked);
  const [messages, setMessages] = useState<Message[]>([]);
  const [pending, setPending] = useState<Pending[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [fatal, setFatal] = useState<ApiError | null>(null);
  const [pollFailing, setPollFailing] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const [focused, setFocused] = useState(false);
  const [foreground, setForeground] = useState(AppState.currentState === 'active');

  const messagesRef = useRef<Message[]>([]);
  const sending = useRef(false); // only sends exclude each other; a poll never blocks a send
  const metaAt = useRef(0);
  const metaBusy = useRef(false);
  const firstLoadDone = useRef(false);

  const merge = useCallback((incoming: Message[]) => {
    const next = mergeMessages(messagesRef.current, incoming);
    messagesRef.current = next;
    setMessages(next);
  }, []);

  const loadMeta = useCallback(
    async (force: boolean) => {
      if (metaBusy.current || (!force && Date.now() - metaAt.current < META_FRESH_MS)) return;
      metaBusy.current = true;
      try {
        const detail = await getThread(threadId);
        metaAt.current = Date.now();
        setMeta(detail);
        setBlocked(detail.blocked);
        setMetaFailed(false);
      } catch (e) {
        const error = toApiError(e);
        if (error.status === 402 || error.status === 403 || error.status === 404) setFatal(error);
        else setMetaFailed(true);
      } finally {
        metaBusy.current = false;
      }
    },
    [threadId],
  );

  const poller = useMemo(
    () =>
      createPoller({
        load: (after) => listMessages(threadId, after),
        onMessages: (incoming) => {
          const held = new Set(messagesRef.current.map((m) => m.id));
          const fresh = incoming.filter((m) => m.sender === 'visitor' && !held.has(m.id));
          merge(incoming);
          // Screen readers hear a new visitor message without having to look for it.
          if (firstLoadDone.current && fresh.length > 0) {
            AccessibilityInfo.announceForAccessibility(t('chat.incoming', { body: fresh[fresh.length - 1].body }));
          }
          firstLoadDone.current = true;
        },
        onSuccess: () => {
          setPollFailing(false);
          setLoaded(true);
          void loadMeta(false); // refreshes the thread and car when stale, and retries after a failure
        },
        onError: () => {
          setPollFailing(true);
          setLoaded(true);
          if (!metaAt.current) void loadMeta(false);
        },
        onFatal: (error) => {
          setFatal(error);
          setLoaded(true);
        },
      }),
    [threadId, merge, loadMeta],
  );

  useFocusEffect(
    useCallback(() => {
      setFocused(true);
      setFatal(null); // coming back to the screen tries again
      return () => setFocused(false);
    }, []),
  );

  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => setForeground(state === 'active'));
    return () => sub.remove();
  }, []);

  // Poll only while the thread is on screen and the app is in the foreground.
  useEffect(() => {
    if (!focused || !foreground || fatal) return;
    poller.start();
    void loadMeta(false);
    return () => poller.stop();
  }, [focused, foreground, fatal, poller, loadMeta]);

  /** Sends `body`. Resolves true when it was accepted, so the caller can clear its input. */
  const send = useCallback(
    async (body: string): Promise<boolean> => {
      if (sending.current) return false;
      sending.current = true;
      const key = ++pendingKey;
      const knownBefore = messagesRef.current.length ? messagesRef.current[messagesRef.current.length - 1].id : 0;
      setSendError(null);
      setPending((list) => [...list.filter((p) => p.status !== 'failed'), { key, body, status: 'sending' }]);
      try {
        merge([await sendMessage(threadId, body)]);
        setPending((list) => list.filter((p) => p.key !== key));
        return true;
      } catch (e) {
        const error = toApiError(e);
        if (error.status === 402) {
          setFatal(error);
          setPending((list) => list.filter((p) => p.key !== key));
        } else if (error.status === 403 || error.status === 422) {
          // Refused for a reason retrying cannot change: the text stays in the input for the owner to edit.
          setSendError(sendRefusal(error));
          setPending((list) => list.filter((p) => p.key !== key));
          if (error.status === 403) void loadMeta(true); // the thread or tag changed: refresh what disables the composer
        } else if (error.status === 0) {
          // No answer (offline or timed out): the message may have been stored. Check before offering Retry.
          setPending((list) =>
            list.map((p) => (p.key === key ? { ...p, status: 'failed', checking: true, error: t('chat.maybeSent') } : p)),
          );
          await poller.pollNow();
          const arrived = messagesRef.current.some((m) => m.sender === 'owner' && m.id > knownBefore && m.body === body);
          setPending((list) =>
            arrived ? list.filter((p) => p.key !== key) : list.map((p) => (p.key === key ? { ...p, checking: false } : p)),
          );
        } else {
          setPending((list) => list.map((p) => (p.key === key ? { ...p, status: 'failed', error: error.message } : p)));
        }
        return false;
      } finally {
        sending.current = false;
      }
    },
    [threadId, merge, loadMeta, poller],
  );

  const discard = useCallback((key: number) => setPending((list) => list.filter((p) => p.key !== key)), []);

  const block = useCallback(async () => {
    setActionError(null);
    try {
      const updated = await blockThread(threadId);
      setBlocked(updated.blocked);
    } catch (e) {
      setActionError(`${t('chat.blockFailed')} ${toApiError(e).message}`);
    }
  }, [threadId]);

  const report = useCallback(
    async (messageId: number) => {
      setActionError(null);
      try {
        merge([await reportMessage(messageId)]);
      } catch (e) {
        setActionError(`${t('chat.reportFailed')} ${toApiError(e).message}`);
      }
    },
    [merge],
  );

  const retryMeta = useCallback(() => void loadMeta(true), [loadMeta]);
  const clearFatal = useCallback(() => setFatal(null), []);

  return {
    meta, metaFailed, blocked, messages, pending, loaded, fatal, pollFailing, sendError, setSendError, actionError,
    send, discard, block, report, retryMeta, clearFatal,
  };
}

import { useCallback, useRef, useState } from 'react';

import { getCar, setChatsPaused, setStealth, type Car } from '../api/cars';
import { toApiError } from '../api/errors';
import { t } from '../i18n';

/**
 * Optimistic switch backed by the server. While the request is out, `pendingValue` holds the requested value so
 * the switch can show it is working, but the car itself is replaced only by the answer the server gives. A refusal
 * leaves the car untouched (the rollback) and sets an error. Taps while a request is out are ignored.
 * After a network error or timeout the request may still have applied, so the car is read again and the server's
 * state wins.
 */
function useServerSwitch(
  send: (id: number, value: boolean) => Promise<Car>,
  read: (car: Car) => boolean,
  failedKey: string,
  onUpdated: (car: Car) => void,
) {
  const [pendingValue, setPendingValue] = useState<boolean | null>(null);
  const [error, setError] = useState<string | null>(null);
  const inFlight = useRef(false);

  const toggle = useCallback(
    async (car: Car) => {
      if (inFlight.current) return;
      inFlight.current = true;
      setError(null);
      const wanted = !read(car);
      setPendingValue(wanted);
      try {
        onUpdated(await send(car.id, wanted));
      } catch (e) {
        const apiError = toApiError(e);
        if (apiError.code === 'network_error') {
          try {
            const fresh = await getCar(car.id);
            onUpdated(fresh);
            if (read(fresh) !== wanted) setError(t(failedKey));
          } catch {
            setError(t(failedKey));
          }
        } else {
          setError(apiError.code === 'subscription_expired' ? apiError.message : t(failedKey));
        }
      } finally {
        inFlight.current = false;
        setPendingValue(null);
      }
    },
    // send, read and failedKey are module-level constants at every call site.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [onUpdated],
  );

  return { pendingValue, error, toggle };
}

export function useStealth(onUpdated: (car: Car) => void) {
  return useServerSwitch(setStealth, (c) => c.stealth_enabled, 'card.stealthFailed', onUpdated);
}

export function useChatsPaused(onUpdated: (car: Car) => void) {
  return useServerSwitch(setChatsPaused, (c) => c.chats_paused, 'settings.chatsPausedFailed', onUpdated);
}

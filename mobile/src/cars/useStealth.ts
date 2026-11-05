import { useCallback, useRef, useState } from 'react';

import { setStealth, type Car } from '../api/cars';
import { toApiError } from '../api/errors';
import { t } from '../i18n';

/**
 * Optimistic stealth switch. While the request is out, `pendingValue` holds the requested value so the switch can
 * move at once, but the car itself is replaced only by the server's answer. A failure leaves the car untouched
 * (the rollback) and sets an error. Taps while a request is out are ignored.
 */
export function useStealth(onUpdated: (car: Car) => void) {
  const [pendingValue, setPendingValue] = useState<boolean | null>(null);
  const [error, setError] = useState<string | null>(null);
  const inFlight = useRef(false);

  const toggle = useCallback(
    async (car: Car) => {
      if (inFlight.current) return;
      inFlight.current = true;
      setError(null);
      setPendingValue(!car.stealth_enabled);
      try {
        onUpdated(await setStealth(car.id, !car.stealth_enabled));
      } catch (e) {
        const apiError = toApiError(e);
        setError(apiError.code === 'subscription_expired' ? apiError.message : t('card.stealthFailed'));
      } finally {
        inFlight.current = false;
        setPendingValue(null);
      }
    },
    [onUpdated],
  );

  return { pendingValue, error, toggle };
}

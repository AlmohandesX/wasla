import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';

import { getCar, type Car } from '../api/cars';
import { toApiError } from '../api/errors';

/** Loads one car each time its screen is shown. `setCar` takes the answer the server gives after a change. */
export function useCar(id: number) {
  const [car, setCar] = useState<Car | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const reload = useCallback(async () => {
    setLoading(true);
    try {
      setCar(await getCar(id));
      setError(null);
    } catch (e) {
      setError(toApiError(e).message);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );

  return { car, setCar, error, loading, reload };
}

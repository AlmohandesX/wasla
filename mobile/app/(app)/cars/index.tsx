import { useRouter, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { listCars, type Car } from '../../../src/api/cars';
import { toApiError } from '../../../src/api/errors';
import { useStealth } from '../../../src/cars/useServerSwitch';
import { Button } from '../../../src/components/Button';
import { CarCard, isExpired } from '../../../src/components/CarCard';
import { ErrorBanner } from '../../../src/components/ErrorBanner';
import { Fab, FAB_HEIGHT } from '../../../src/components/Fab';
import { Screen } from '../../../src/components/Screen';
import { t, useLocale } from '../../../src/i18n';
import { colors, font, fontSize, spacing } from '../../../src/theme';

function CarRow({ car, onUpdated }: { car: Car; onUpdated: (car: Car) => void }) {
  const router = useRouter();
  const stealth = useStealth(onUpdated);
  return (
    <CarCard
      car={car}
      stealthPendingValue={stealth.pendingValue}
      stealthError={stealth.error}
      onToggleStealth={() => stealth.toggle(car)}
      onOpen={() =>
        router.push({ pathname: isExpired(car) ? '/cars/[id]/expired' : '/cars/[id]', params: { id: String(car.id) } })
      }
      onLinkTag={() => router.push({ pathname: '/cars/scan', params: { carId: String(car.id) } })}
    />
  );
}

/** "My cars": every car with its tag and stealth switch, opened from the Cars card on Home. */
export default function Cars() {
  useLocale();
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const [cars, setCars] = useState<Car[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setCars(await listCars());
      setError(null);
    } catch (e) {
      setError(toApiError(e).message);
    } finally {
      setLoading(false);
    }
  }, []);

  // Reload whenever the screen is shown again, so a status that ran out or a car added elsewhere shows up.
  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  const replace = useCallback((updated: Car) => {
    setCars((list) => (list ? list.map((c) => (c.id === updated.id ? updated : c)) : list));
  }, []);

  return (
    <View style={styles.flex}>
      {/* The title is in the navy header above, set in app/(app)/_layout.tsx. */}
      <Screen bottomSpace={FAB_HEIGHT + spacing.md + insets.bottom}>
        {error ? <ErrorBanner message={`${t('cars.loadError')} ${error}`} /> : null}
        {cars === null && loading ? (
          <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} />
        ) : null}
        {cars === null && error && !loading ? <Button label={t('common.retry')} onPress={load} /> : null}
        {cars?.length === 0 ? <Text style={styles.empty}>{t('cars.empty')}</Text> : null}
        {cars?.map((car) => <CarRow key={car.id} car={car} onUpdated={replace} />)}
      </Screen>
      <Fab
        icon="qr-code-outline"
        label={t('cars.addShort')}
        accessibilityLabel={t('cars.add')}
        onPress={() => router.push('/cars/scan')}
        bottomInset={insets.bottom}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  empty: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.text, marginVertical: spacing.lg },
});

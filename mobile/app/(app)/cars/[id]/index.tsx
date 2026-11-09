import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Image, StyleSheet, Text, View } from 'react-native';

import { carPhotoUrl } from '../../../../src/api/cars';
import { getToken } from '../../../../src/auth/token';
import { useCar } from '../../../../src/cars/useCar';
import { useStealth } from '../../../../src/cars/useServerSwitch';
import { Button } from '../../../../src/components/Button';
import { CarCard, isExpired } from '../../../../src/components/CarCard';
import { ErrorBanner } from '../../../../src/components/ErrorBanner';
import { Screen } from '../../../../src/components/Screen';
import { t, useLocale } from '../../../../src/i18n';
import { colors, font, fontSize, radius, spacing } from '../../../../src/theme';

function CarPhoto({ carId, version }: { carId: number; version: number }) {
  const [token, setToken] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    setFailed(false);
    void getToken().then(setToken);
  }, [version]);

  if (!token || failed) {
    return (
      <View style={styles.photoEmpty}>
        <Text style={styles.photoEmptyText}>{t('detail.noPhoto')}</Text>
      </View>
    );
  }
  return (
    <Image
      accessibilityLabel={t('detail.photoAlt')}
      // The photo is owner-only, so the request carries the bearer token; the query keeps a replaced photo fresh.
      source={{ uri: `${carPhotoUrl(carId)}?v=${version}`, headers: { Authorization: `Bearer ${token}` } }}
      onError={() => setFailed(true)}
      style={styles.photo}
    />
  );
}

/** An expired car has no normal screen: this one sends the owner to its hard stop. */
function GoToExpired({ carId }: { carId: number }) {
  const router = useRouter();
  useEffect(() => {
    router.replace({ pathname: '/cars/[id]/expired', params: { id: String(carId) } });
  }, [carId, router]);
  return (
    <Screen>
      <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} />
    </Screen>
  );
}

type Target = '/cars/[id]/reminders' | '/cars/[id]/status' | '/cars/[id]/settings' | '/cars/[id]/transfer';

export default function CarDetail() {
  useLocale();
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const carId = Number(id);
  const { car, setCar, error, loading } = useCar(carId);
  const stealth = useStealth(setCar);
  // Each time this screen mounts the photo is fetched again, so a photo just uploaded in settings shows.
  const [photoVersion] = useState(() => Date.now());

  if (!car) {
    return (
      <Screen>
        {error ? <ErrorBanner message={error} /> : null}
        {loading ? <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} /> : null}
      </Screen>
    );
  }

  const usable = !isExpired(car);
  if (!usable) return <GoToExpired carId={car.id} />;
  const go = (pathname: Target) => () => router.push({ pathname, params: { id: String(car.id) } });

  return (
    <Screen>
      {error ? <ErrorBanner message={error} /> : null}
      <CarPhoto carId={car.id} version={photoVersion} />
      <CarCard
        car={car}
        stealthPendingValue={stealth.pendingValue}
        stealthError={stealth.error}
        onToggleStealth={() => stealth.toggle(car)}
        onOpen={() => {}}
        onLinkTag={() => router.push({ pathname: '/cars/scan', params: { carId: String(car.id) } })}
      />
      {usable ? (
        <View style={styles.actions}>
          <Button label={t('detail.status')} onPress={go('/cars/[id]/status')} />
          <Button label={t('detail.chats')} variant="secondary" onPress={() => router.push('/chats')} />
          <Button label={t('detail.reminders')} variant="secondary" onPress={go('/cars/[id]/reminders')} />
          <Button label={t('detail.settings')} variant="secondary" onPress={go('/cars/[id]/settings')} />
          {car.linked ? <Button label={t('detail.transfer')} variant="secondary" onPress={go('/cars/[id]/transfer')} /> : null}
        </View>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  photo: { width: '100%', height: 200, borderRadius: radius, marginBottom: spacing.md, backgroundColor: colors.primaryTint },
  photoEmpty: {
    height: 120,
    borderRadius: radius,
    marginBottom: spacing.md,
    backgroundColor: colors.primaryTint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  photoEmptyText: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.textMuted },
  actions: { gap: spacing.sm },
});

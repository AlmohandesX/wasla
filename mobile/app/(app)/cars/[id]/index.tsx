import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Image, StyleSheet, Text, View } from 'react-native';

import { carPhotoUrl } from '../../../../src/api/cars';
import { getToken } from '../../../../src/auth/token';
import { useCar } from '../../../../src/cars/useCar';
import { useStealth } from '../../../../src/cars/useServerSwitch';
import { CarActionBar, type CarAction } from '../../../../src/components/CarActionBar';
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
      // Fills the 16:9 frame without stretching; a photo of another shape (an older upload) is trimmed to fit.
      resizeMode="cover"
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

type Target = '/cars/[id]/reminders' | '/cars/[id]/status' | '/cars/[id]/settings';

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

  if (isExpired(car)) return <GoToExpired carId={car.id} />;
  const go = (pathname: Target) => () => router.push({ pathname, params: { id: String(car.id) } });

  // Transferring the tag lives in Settings: it is rare and hard to undo, so it does not need a place here.
  const actions: CarAction[] = [
    { key: 'status', icon: 'megaphone-outline', label: t('carNav.status'), accessibilityLabel: t('detail.status'), onPress: go('/cars/[id]/status') },
    { key: 'chats', icon: 'chatbubbles-outline', label: t('detail.chats'), onPress: () => router.push('/chats') },
    { key: 'reminders', icon: 'alarm-outline', label: t('detail.reminders'), onPress: go('/cars/[id]/reminders') },
    { key: 'settings', icon: 'settings-outline', label: t('carNav.settings'), accessibilityLabel: t('detail.settings'), onPress: go('/cars/[id]/settings') },
  ];

  return (
    <View style={styles.page}>
      <Screen tabbed>
        <View style={styles.content}>
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
        </View>
      </Screen>
      <CarActionBar actions={actions} />
    </View>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.background },
  // A readable column on tablets and the web, centred; on a phone it is simply the full width.
  content: { width: '100%', maxWidth: 560, alignSelf: 'center' },
  // The same 16:9 shape the picker crops to (PHOTO_ASPECT), at any screen width.
  photo: { width: '100%', aspectRatio: 16 / 9, borderRadius: radius, marginBottom: spacing.md, backgroundColor: colors.primaryTint },
  photoEmpty: {
    width: '100%',
    aspectRatio: 16 / 9,
    borderRadius: radius,
    marginBottom: spacing.md,
    backgroundColor: colors.primaryTint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  photoEmptyText: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.textMuted },
});

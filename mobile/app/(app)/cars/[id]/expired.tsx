import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';

import { useCar } from '../../../../src/cars/useCar';
import { Button } from '../../../../src/components/Button';
import { isExpired } from '../../../../src/components/CarCard';
import { ErrorBanner } from '../../../../src/components/ErrorBanner';
import { Screen } from '../../../../src/components/Screen';
import { t, useLocale } from '../../../../src/i18n';
import { formatDate } from '../../../../src/lib/format';
import { colors, font, fontSize, radius, spacing } from '../../../../src/theme';

/** The hard stop for one car whose subscription ended. Renewal, release and account settings stay reachable. */
export default function Expired() {
  useLocale();
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const carId = Number(id);
  const { car, error, loading, reload } = useCar(carId);

  // A car that is no longer expired (just renewed, or the server says it is fine) goes to its normal screen.
  const stillFine = car !== null && !isExpired(car);
  useEffect(() => {
    if (stillFine) router.replace({ pathname: '/cars/[id]', params: { id: String(carId) } });
  }, [stillFine, carId, router]);

  const go = (pathname: '/cars/[id]/renew' | '/cars/[id]/transfer') => () =>
    router.push({ pathname, params: { id: String(carId) } });

  return (
    <Screen>
      <Text accessibilityRole="header" style={styles.title}>
        {t('expired.title')}
      </Text>
      {error ? <ErrorBanner message={error} /> : null}
      {!car && loading ? <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} /> : null}
      {!car && error && !loading ? <Button label={t('common.retry')} onPress={reload} /> : null}
      {car ? (
        <View style={styles.box}>
          <Text style={styles.body}>
            {car.subscription.expires_at
              ? t('expired.body', { model: car.model, date: formatDate(car.subscription.expires_at) })
              : t('expired.bodyNoDate', { model: car.model })}
          </Text>
        </View>
      ) : null}
      <View style={styles.actions}>
        <Button label={t('expired.renew')} onPress={go('/cars/[id]/renew')} disabled={!car || !car.linked} />
        <Text style={styles.hint}>{t('expired.unlinkHint')}</Text>
        <Button label={t('expired.unlink')} variant="secondary" onPress={go('/cars/[id]/transfer')} disabled={!car || !car.linked} />
        <Button label={t('expired.account')} variant="secondary" onPress={() => router.push('/account')} />
        <Button label={t('expired.back')} variant="link" onPress={() => router.dismissTo('/cars')} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: fontSize.title, fontFamily: font.bold, color: colors.danger, marginBottom: spacing.md },
  box: {
    backgroundColor: colors.dangerTint,
    borderColor: colors.danger,
    borderWidth: 1,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  body: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.text },
  actions: { gap: spacing.sm },
  hint: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.textMuted, marginTop: spacing.sm },
});

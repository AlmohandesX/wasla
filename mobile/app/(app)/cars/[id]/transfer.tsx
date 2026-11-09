import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { unlinkTag } from '../../../../src/api/cars';
import { toApiError } from '../../../../src/api/errors';
import { useCar } from '../../../../src/cars/useCar';
import { Button } from '../../../../src/components/Button';
import { Checkbox } from '../../../../src/components/Checkbox';
import { ErrorBanner } from '../../../../src/components/ErrorBanner';
import { Screen } from '../../../../src/components/Screen';
import { t, useLocale } from '../../../../src/i18n';
import { colors, font, fontSize, radius, spacing } from '../../../../src/theme';

export default function Transfer() {
  useLocale();
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const carId = Number(id);
  const { car, setCar } = useCar(carId);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const release = async () => {
    setBusy(true);
    setError(null);
    try {
      setCar(await unlinkTag(carId));
      setDone(true);
    } catch (e) {
      setError(toApiError(e).message);
    } finally {
      setBusy(false);
    }
  };

  const hasTag = car?.linked === true && !done;

  return (
    <Screen>
      <ErrorBanner message={error} />
      {done ? <ErrorBanner tone="info" message={t('transfer.done')} /> : null}
      {car && !car.linked && !done ? <ErrorBanner tone="info" message={t('transfer.unlinked')} /> : null}
      <View style={styles.warning}>
        <Text accessibilityRole="alert" style={styles.warningText}>
          {t('transfer.warning')}
        </Text>
        <Text style={styles.stays}>{t('transfer.stays')}</Text>
      </View>
      {hasTag ? (
        <>
          <Checkbox label={t('transfer.confirm')} checked={confirmed} onChange={setConfirmed} />
          <Button label={t('transfer.submit')} loading={busy} disabled={!confirmed} onPress={release} />
        </>
      ) : (
        <Button label={t('cars.title')} variant="secondary" onPress={() => router.dismissTo('/cars')} />
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  warning: {
    backgroundColor: colors.dangerTint,
    borderColor: colors.danger,
    borderWidth: 1,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  warningText: { fontSize: fontSize.body, fontFamily: font.semibold, color: colors.danger },
  stays: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.text, marginTop: spacing.sm },
});

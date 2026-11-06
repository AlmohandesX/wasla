import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { deleteCar, type Car } from '../api/cars';
import { toApiError } from '../api/errors';
import { t } from '../i18n';
import { colors, fontSize, radius, spacing } from '../theme';
import { Button } from './Button';
import { Checkbox } from './Checkbox';
import { ErrorBanner } from './ErrorBanner';
import { isExpired } from './CarCard';

type Props = { car: Car; onRemoved: () => void; onReleaseFirst: () => void };

/**
 * Removes the car from the account after a confirmation. The server refuses this for a car whose sticker is linked
 * but expired (the hard stop), so that car is sent to release the sticker first.
 */
export function RemoveCar({ car, onRemoved, onReleaseFirst }: Props) {
  const [open, setOpen] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const remove = async () => {
    setBusy(true);
    setError(null);
    try {
      await deleteCar(car.id);
      onRemoved();
    } catch (e) {
      setError(toApiError(e).message);
      setBusy(false);
    }
  };

  return (
    <View>
      <Text accessibilityRole="header" style={styles.heading}>
        {t('removeCar.title')}
      </Text>
      {isExpired(car) ? (
        <>
          <Text style={styles.note}>{t('removeCar.releaseFirst')}</Text>
          <Button label={t('removeCar.release')} variant="secondary" onPress={onReleaseFirst} />
        </>
      ) : !open ? (
        <Button label={t('removeCar.start')} variant="secondary" onPress={() => setOpen(true)} />
      ) : (
        <>
          <View style={styles.warning}>
            <Text accessibilityRole="alert" style={styles.warningText}>
              {t('removeCar.what')}
            </Text>
            {car.linked ? <Text style={styles.body}>{t('removeCar.sticker')}</Text> : null}
          </View>
          <ErrorBanner message={error} />
          <Checkbox label={t('removeCar.confirm')} checked={confirmed} onChange={setConfirmed} />
          <View style={styles.stack}>
            <Button label={t('removeCar.submit')} variant="danger" loading={busy} disabled={!confirmed} onPress={remove} />
            <Button label={t('removeCar.cancel')} variant="link" disabled={busy} onPress={() => setOpen(false)} />
          </View>
        </>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  heading: { fontSize: fontSize.body + 2, fontWeight: '700', color: colors.text, marginBottom: spacing.sm },
  note: { fontSize: fontSize.small, color: colors.textMuted, marginBottom: spacing.sm },
  stack: { gap: spacing.sm },
  warning: {
    backgroundColor: colors.dangerTint,
    borderColor: colors.danger,
    borderWidth: 1,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  warningText: { fontSize: fontSize.body, fontWeight: '600', color: colors.danger },
  body: { fontSize: fontSize.small, color: colors.text, marginTop: spacing.sm },
});

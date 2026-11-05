import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { Car } from '../api/cars';
import { t, useLocale } from '../i18n';
import { formatDateTime } from '../lib/format';
import { colors, fontSize, radius, spacing, touchTarget } from '../theme';
import { Button } from './Button';
import { ErrorBanner } from './ErrorBanner';

type Props = {
  car: Car;
  onOpen: () => void;
  onToggleStealth: () => void;
  /** Scan a tag for a car that has none (shown only for an unlinked car). */
  onLinkTag?: () => void;
  /** The stealth value being requested while the server has not answered yet, otherwise null/undefined. */
  stealthPendingValue?: boolean | null;
  stealthError?: string | null;
};

/** A linked car whose tag subscription has run out. Task 21 adds the renewal screen. */
export function isExpired(car: Car): boolean {
  return car.linked && !car.subscription.active;
}

function activeStatus(car: Car) {
  return car.status && new Date(car.status.expires_at).getTime() > Date.now() ? car.status : null;
}

export function CarCard({ car, onOpen, onToggleStealth, onLinkTag, stealthPendingValue, stealthError }: Props) {
  useLocale();
  const expired = isExpired(car);
  const pending = stealthPendingValue !== null && stealthPendingValue !== undefined;
  // Only the server's answer decides the look: a pending request never shows the card as stealthed.
  const stealth = car.stealth_enabled && !pending;
  const status = activeStatus(car);

  return (
    <View testID="car-card" style={[styles.card, stealth && styles.cardStealth, expired && styles.cardExpired]}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t('card.openLabel', { model: car.model, plate: car.plate })}
        disabled={expired}
        onPress={onOpen}
        style={styles.head}
      >
        <Text style={[styles.model, stealth && styles.textStealth]}>{car.model}</Text>
        <Text style={[styles.plate, stealth && styles.textStealth]}>{car.plate}</Text>
      </Pressable>

      {status ? (
        <Text style={[styles.status, stealth && styles.textStealth]}>
          {t('card.status', { text: status.text })} ({t('card.statusUntil', { time: formatDateTime(status.expires_at) })})
        </Text>
      ) : null}

      {expired ? (
        <View style={styles.notice}>
          <Text accessibilityRole="alert" style={styles.noticeTitle}>
            {t('card.expired')}
          </Text>
          <Text style={styles.noticeBody}>{t('card.expiredBody')}</Text>
        </View>
      ) : null}

      {!car.linked ? (
        <View style={styles.notice}>
          <Text style={styles.noticeTitle}>{t('card.unlinked')}</Text>
          {onLinkTag ? <Button label={t('card.linkTag')} variant="secondary" onPress={onLinkTag} /> : null}
        </View>
      ) : null}

      {car.linked && !expired ? (
        <View>
          <Pressable
            accessibilityRole="switch"
            accessibilityState={{ checked: stealth, busy: pending }}
            accessibilityLabel={t(car.stealth_enabled ? 'card.stealthSwitchOff' : 'card.stealthSwitchOn', {
              model: car.model,
            })}
            disabled={pending}
            onPress={onToggleStealth}
            style={[styles.switchRow, stealth && styles.switchRowStealth]}
          >
            <View style={[styles.track, stealth && styles.trackOn]}>
              <View style={[styles.knob, stealth && styles.knobOn]} />
            </View>
            <View style={styles.switchText}>
              <Text style={[styles.switchLabel, stealth && styles.textStealth]}>
                {pending ? t('card.stealthPending') : stealth ? t('card.stealthOn') : t('card.stealthOff')}
              </Text>
              {stealth ? <Text style={styles.switchHint}>{t('card.stealthOnHint')}</Text> : null}
            </View>
          </Pressable>
          <ErrorBanner message={stealthError} />
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderStyle: 'solid',
    borderColor: colors.border,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  // Stealth: dark fill and a dashed border plus a text label, so it never relies on color alone.
  cardStealth: { backgroundColor: colors.text, borderStyle: 'dashed', borderColor: colors.surface, borderWidth: 2 },
  cardExpired: { backgroundColor: colors.dangerTint, borderColor: colors.danger },
  head: { minHeight: touchTarget, justifyContent: 'center' },
  model: { fontSize: fontSize.body + 2, fontWeight: '700', color: colors.text },
  plate: { fontSize: fontSize.body, color: colors.textMuted, marginTop: spacing.xs },
  textStealth: { color: colors.surface },
  status: { fontSize: fontSize.small, color: colors.text, marginTop: spacing.sm },
  notice: { marginTop: spacing.sm },
  noticeTitle: { fontSize: fontSize.body, fontWeight: '700', color: colors.danger },
  noticeBody: { fontSize: fontSize.small, color: colors.text, marginTop: spacing.xs },
  switchRow: { flexDirection: 'row', alignItems: 'center', minHeight: touchTarget, marginTop: spacing.sm },
  switchRowStealth: {},
  switchText: { flex: 1, marginLeft: spacing.md },
  switchLabel: { fontSize: fontSize.body, fontWeight: '600', color: colors.text },
  switchHint: { fontSize: fontSize.small, color: colors.surface, marginTop: spacing.xs },
  track: {
    width: 48,
    height: 28,
    borderRadius: 14,
    borderWidth: 2,
    borderColor: colors.primaryDark,
    backgroundColor: colors.surface,
    justifyContent: 'center',
  },
  trackOn: { backgroundColor: colors.primary, borderColor: colors.surface },
  knob: { width: 18, height: 18, borderRadius: 9, backgroundColor: colors.primaryDark, marginLeft: 3 },
  knobOn: { backgroundColor: colors.surface, marginLeft: 23 },
});

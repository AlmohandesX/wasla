import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { clearStatus, MAX_STATUS_LENGTH, setStatus } from '../../../../src/api/cars';
import { fieldError, toApiError, type ApiError } from '../../../../src/api/errors';
import { useCar } from '../../../../src/cars/useCar';
import { Button } from '../../../../src/components/Button';
import { ErrorBanner } from '../../../../src/components/ErrorBanner';
import { Screen } from '../../../../src/components/Screen';
import { TextField } from '../../../../src/components/TextField';
import { t, useLocale } from '../../../../src/i18n';
import { formatDateTime } from '../../../../src/lib/format';
import { colors, fontSize, radius, spacing, touchTarget } from '../../../../src/theme';

const DURATIONS = [1, 15, 60, 480, 1440, 10080]; // minutes; the backend allows 1 to 10080

export default function StatusScreen() {
  useLocale();
  const { id } = useLocalSearchParams<{ id: string }>();
  const carId = Number(id);
  const { car, setCar } = useCar(carId);
  const [text, setText] = useState('');
  const [minutes, setMinutes] = useState(60);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [emptyError, setEmptyError] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    setError(null);
    setDone(null);
    try {
      await action();
    } catch (e) {
      setError(toApiError(e));
    } finally {
      setBusy(false);
    }
  };

  // A refusal about the note itself (moderation or validation) is shown beside the field, in plain words.
  const textError = emptyError
    ? t('field.required')
    : error?.code === 'message_blocked'
      ? error.message
      : fieldError(error, 'text');
  const status = car?.status && new Date(car.status.expires_at).getTime() > Date.now() ? car.status : null;

  return (
    <Screen>
      <Text style={styles.intro}>{t('status.intro')}</Text>
      {done ? <ErrorBanner tone="info" message={done} /> : null}
      {error && !textError ? <ErrorBanner message={error.message} /> : null}
      {status ? (
        <View style={styles.current}>
          <Text style={styles.currentText}>
            {t('status.current', { text: status.text })} ({t('card.statusUntil', { time: formatDateTime(status.expires_at) })})
          </Text>
          <Button
            label={t('status.clear')}
            variant="secondary"
            disabled={busy}
            onPress={() =>
              run(async () => {
                setCar(await clearStatus(carId));
                setDone(t('status.cleared'));
              })
            }
          />
        </View>
      ) : null}
      <TextField
        label={t('status.text')}
        value={text}
        onChangeText={(value) => {
          setText(value);
          setEmptyError(false);
        }}
        maxLength={MAX_STATUS_LENGTH}
        multiline
        hint={t('status.counter', { count: text.length, max: MAX_STATUS_LENGTH })}
        error={textError}
      />
      <Text style={styles.groupLabel}>{t('status.duration')}</Text>
      <View style={styles.chips}>
        {DURATIONS.map((d) => {
          const selected = d === minutes;
          return (
            <Pressable
              key={d}
              accessibilityRole="radio"
              accessibilityState={{ selected }}
              accessibilityLabel={t(`status.d${d}`)}
              onPress={() => setMinutes(d)}
              style={[styles.chip, selected && styles.chipSelected]}
            >
              <Text style={[styles.chipText, selected && styles.chipTextSelected]}>{t(`status.d${d}`)}</Text>
            </Pressable>
          );
        })}
      </View>
      <Button
        label={t('status.set')}
        loading={busy}
        onPress={() => {
          if (text.trim() === '') {
            setEmptyError(true);
            return;
          }
          void run(async () => {
            setCar(await setStatus(carId, text.trim(), minutes));
            setText('');
            setDone(t('status.saved'));
          });
        }}
      />
    </Screen>
  );
}

const styles = StyleSheet.create({
  intro: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.md },
  current: { marginBottom: spacing.md },
  currentText: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.sm },
  groupLabel: { fontSize: fontSize.small, fontWeight: '600', color: colors.text, marginBottom: spacing.xs },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.lg },
  chip: {
    minHeight: touchTarget,
    paddingHorizontal: spacing.md,
    borderRadius: radius,
    borderWidth: 1,
    borderColor: colors.border,
    justifyContent: 'center',
  },
  chipSelected: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipText: { fontSize: fontSize.body, color: colors.text },
  chipTextSelected: { color: colors.surface, fontWeight: '700' },
});

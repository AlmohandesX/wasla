import { useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';

import { toApiError, fieldError, type ApiError } from '../../../../src/api/errors';
import { addReminder, deleteReminder, listReminders, REMINDER_TYPES, type Reminder, type ReminderType } from '../../../../src/api/reminders';
import { Button } from '../../../../src/components/Button';
import { ErrorBanner } from '../../../../src/components/ErrorBanner';
import { Screen } from '../../../../src/components/Screen';
import { TextField } from '../../../../src/components/TextField';
import { t, useLocale } from '../../../../src/i18n';
import { formatDueDate, parseDateInput, todayInIstanbul } from '../../../../src/lib/dates';
import { colors, fontSize, radius, spacing, touchTarget } from '../../../../src/theme';

export default function Reminders() {
  useLocale();
  const { id } = useLocalSearchParams<{ id: string }>();
  const carId = Number(id);
  const [items, setItems] = useState<Reminder[] | null>(null);
  const [type, setType] = useState<ReminderType>('oil_change');
  const [date, setDate] = useState('');
  const [dateError, setDateError] = useState<string | undefined>();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setItems(await listReminders(carId));
      setError(null);
    } catch (e) {
      setError(`${t('reminders.loadError')} ${toApiError(e).message}`);
    }
  }, [carId]);

  useEffect(() => {
    void load();
  }, [load]);

  const add = async () => {
    setError(null);
    const iso = parseDateInput(date);
    if (!iso) return setDateError(t('reminders.dateInvalid'));
    if (iso < todayInIstanbul()) return setDateError(t('reminders.datePast'));
    setDateError(undefined);
    setBusy(true);
    try {
      const created = await addReminder(carId, { type, due_on: iso });
      setItems((list) => [...(list ?? []), created].sort((a, b) => a.due_on.localeCompare(b.due_on)));
      setDate('');
    } catch (e) {
      const apiError: ApiError = toApiError(e);
      const near = fieldError(apiError, 'due_on');
      if (near) setDateError(near);
      else setError(apiError.message);
    } finally {
      setBusy(false);
    }
  };

  const remove = async (reminder: Reminder) => {
    setError(null);
    try {
      await deleteReminder(reminder.id);
      setItems((list) => (list ?? []).filter((r) => r.id !== reminder.id));
    } catch (e) {
      setError(`${t('reminders.deleteFailed')} ${toApiError(e).message}`);
    }
  };

  return (
    <Screen>
      <Text style={styles.note}>{t('reminders.intro')}</Text>
      <ErrorBanner message={error} />
      {items === null && !error ? <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} /> : null}
      {items?.length === 0 ? <Text style={styles.empty}>{t('reminders.empty')}</Text> : null}
      {items?.map((reminder) => {
        const name = t(`reminders.${reminder.type}`);
        const when = formatDueDate(reminder.due_on);
        return (
          <View key={reminder.id} style={styles.item}>
            <Text style={styles.itemText}>{t('reminders.due', { type: name, date: when })}</Text>
            <Button
              label={t('reminders.delete')}
              accessibilityLabel={t('reminders.deleteLabel', { type: name, date: when })}
              variant="secondary"
              onPress={() => remove(reminder)}
            />
          </View>
        );
      })}

      <View style={styles.form}>
        <Text style={styles.label}>{t('reminders.type')}</Text>
        <View accessibilityRole="radiogroup" style={styles.types}>
          {REMINDER_TYPES.map((value) => {
            const selected = value === type;
            return (
              <Pressable
                key={value}
                accessibilityRole="radio"
                accessibilityState={{ selected }}
                onPress={() => setType(value)}
                style={[styles.type, selected && styles.typeSelected]}
              >
                <Text style={[styles.typeText, selected && styles.typeTextSelected]}>{t(`reminders.${value}`)}</Text>
              </Pressable>
            );
          })}
        </View>
        <TextField
          label={t('reminders.date')}
          hint={t('reminders.dateHint')}
          value={date}
          onChangeText={setDate}
          keyboardType="numeric"
          maxLength={10}
          error={dateError}
        />
        <Button label={t('reminders.add')} loading={busy} onPress={add} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  note: { fontSize: fontSize.small, color: colors.textMuted, marginBottom: spacing.md },
  empty: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.md },
  item: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing.sm, gap: spacing.sm },
  itemText: { flex: 1, fontSize: fontSize.body, fontWeight: '600', color: colors.text },
  form: { marginTop: spacing.lg },
  label: { fontSize: fontSize.small, fontWeight: '600', color: colors.text, marginBottom: spacing.xs },
  types: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.md },
  type: {
    flex: 1,
    minHeight: touchTarget,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.sm,
  },
  typeSelected: { borderWidth: 2, borderColor: colors.primaryDark, backgroundColor: colors.primaryTint },
  typeText: { fontSize: fontSize.body, color: colors.text, textAlign: 'center' },
  typeTextSelected: { fontWeight: '700', color: colors.primaryDark },
});

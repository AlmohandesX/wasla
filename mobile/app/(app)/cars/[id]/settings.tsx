import { useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import {
  addContact,
  deleteContact,
  deletePhoto,
  listContacts,
  updateCar,
  uploadPhoto,
  type Contact,
} from '../../../../src/api/cars';
import { ApiError, fieldError, toApiError } from '../../../../src/api/errors';
import { useCar } from '../../../../src/cars/useCar';
import { pickPhoto } from '../../../../src/cars/pickPhoto';
import { Button } from '../../../../src/components/Button';
import { ErrorBanner } from '../../../../src/components/ErrorBanner';
import { Screen } from '../../../../src/components/Screen';
import { TextField } from '../../../../src/components/TextField';
import { t, useLocale } from '../../../../src/i18n';
import { normalizePhone } from '../../../../src/lib/phone';
import { colors, fontSize, radius, spacing } from '../../../../src/theme';

/** The message to show beside a field: a moderation refusal about this text, or the server's validation message. */
function beside(error: ApiError | null, field: string): string | undefined {
  if (!error) return undefined;
  return error.code === 'message_blocked' ? error.message : fieldError(error, field);
}

function CarDetails({ carId }: { carId: number }) {
  const { car, setCar } = useCar(carId);
  const [model, setModel] = useState('');
  const [plate, setPlate] = useState('');
  const [message, setMessage] = useState('');
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (car && !loaded) {
      setModel(car.model);
      setPlate(car.plate);
      setMessage(car.default_message ?? '');
      setLoaded(true);
    }
  }, [car, loaded]);

  // The backend names the field that was refused; a moderation refusal can only be about the default message.
  const messageError = error?.code === 'message_blocked' ? error.message : fieldError(error, 'default_message');
  const generic = error && !messageError && !fieldError(error, 'model') && !fieldError(error, 'plate');

  const save = async () => {
    setBusy(true);
    setError(null);
    setSaved(false);
    try {
      setCar(await updateCar(carId, { model: model.trim(), plate: plate.trim(), default_message: message.trim() || null }));
      setSaved(true);
    } catch (e) {
      setError(toApiError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <View>
      {generic ? <ErrorBanner message={error.message} /> : null}
      {saved ? <ErrorBanner tone="info" message={t('settings.saved')} /> : null}
      <TextField label={t('field.model')} value={model} onChangeText={setModel} maxLength={120} error={fieldError(error, 'model')} />
      <TextField label={t('field.plate')} value={plate} onChangeText={setPlate} maxLength={32} autoCapitalize="characters" error={fieldError(error, 'plate')} />
      <TextField
        label={t('field.defaultMessage')}
        hint={t('field.defaultMessageHint')}
        value={message}
        onChangeText={setMessage}
        maxLength={280}
        multiline
        error={messageError}
      />
      <Button label={t('settings.save')} loading={busy} disabled={!loaded} onPress={save} />
    </View>
  );
}

function PhotoSection({ carId }: { carId: number }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const run = async (action: () => Promise<string | null>) => {
    setBusy(true);
    setError(null);
    setDone(null);
    try {
      setDone(await action());
    } catch (e) {
      setError(toApiError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <View>
      <Text accessibilityRole="header" style={styles.heading}>
        {t('settings.photo')}
      </Text>
      <Text style={styles.note}>{t('settings.photoHint')}</Text>
      {error ? <ErrorBanner message={fieldError(error, 'photo') ?? error.message} /> : null}
      {done ? <ErrorBanner tone="info" message={done} /> : null}
      <View style={styles.stack}>
        <Button
          label={t('settings.photoPick')}
          variant="secondary"
          loading={busy}
          onPress={() =>
            run(async () => {
              const photo = await pickPhoto().catch(() => {
                throw new ApiError(0, 'unreadable', t('settings.photoUnreadable'));
              });
              if (!photo) return null;
              await uploadPhoto(carId, photo);
              return t('settings.photoDone');
            })
          }
        />
        <Button
          label={t('settings.photoRemove')}
          variant="secondary"
          disabled={busy}
          onPress={() =>
            run(async () => {
              await deletePhoto(carId);
              return t('settings.photoRemoved');
            })
          }
        />
      </View>
    </View>
  );
}

function Contacts({ carId }: { carId: number }) {
  const [contacts, setContacts] = useState<Contact[] | null>(null);
  const [label, setLabel] = useState('');
  const [phone, setPhone] = useState('');
  const [labelError, setLabelError] = useState<string | undefined>();
  const [phoneError, setPhoneError] = useState<string | undefined>();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      setContacts(await listContacts(carId));
    } catch (e) {
      setError(toApiError(e).message);
    }
  }, [carId]);

  useEffect(() => {
    void load();
  }, [load]);

  const add = async () => {
    setError(null);
    const normalized = normalizePhone(phone);
    const missingLabel = label.trim() === '';
    setLabelError(missingLabel ? t('field.required') : undefined);
    setPhoneError(normalized === null ? t('field.phoneInvalid') : undefined);
    if (missingLabel || normalized === null) return;

    setBusy(true);
    try {
      const created = await addContact(carId, { label: label.trim(), phone: normalized });
      setContacts((list) => [...(list ?? []), created]);
      setLabel('');
      setPhone('');
    } catch (e) {
      const apiError = toApiError(e);
      const nearLabel = beside(apiError, 'label');
      const nearPhone = fieldError(apiError, 'phone');
      setLabelError(nearLabel);
      setPhoneError(nearPhone);
      if (!nearLabel && !nearPhone) setError(apiError.message);
    } finally {
      setBusy(false);
    }
  };

  const remove = async (contact: Contact) => {
    setError(null);
    try {
      await deleteContact(contact.id);
      setContacts((list) => (list ?? []).filter((c) => c.id !== contact.id));
    } catch (e) {
      setError(toApiError(e).message);
    }
  };

  return (
    <View>
      <Text accessibilityRole="header" style={styles.heading}>
        {t('contacts.title')}
      </Text>
      <View style={styles.warning}>
        <Text accessibilityRole="alert" style={styles.warningText}>
          {t('contacts.warning')}
        </Text>
      </View>
      <ErrorBanner message={error} />
      {contacts?.length === 0 ? <Text style={styles.note}>{t('contacts.empty')}</Text> : null}
      {contacts?.map((contact) => (
        <View key={contact.id} style={styles.contact}>
          <View style={styles.contactText}>
            <Text style={styles.contactLabel}>{contact.label}</Text>
            <Text style={styles.note}>{contact.phone}</Text>
          </View>
          <Button
            label={t('contacts.deleteShort')}
            accessibilityLabel={t('contacts.delete', { label: contact.label })}
            variant="secondary"
            onPress={() => remove(contact)}
          />
        </View>
      ))}
      <TextField label={t('field.contactLabel')} value={label} onChangeText={setLabel} maxLength={60} error={labelError} />
      <TextField
        label={t('field.contactPhone')}
        hint={t('field.contactPhoneHint')}
        value={phone}
        onChangeText={setPhone}
        keyboardType="phone-pad"
        error={phoneError}
      />
      <Button label={t('contacts.add')} loading={busy} onPress={add} />
    </View>
  );
}

export default function Settings() {
  useLocale();
  const { id } = useLocalSearchParams<{ id: string }>();
  const carId = Number(id);
  return (
    <Screen>
      <CarDetails carId={carId} />
      <View style={styles.section}>
        <PhotoSection carId={carId} />
      </View>
      <View style={styles.section}>
        <Contacts carId={carId} />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  section: { marginTop: spacing.xl },
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
  warningText: { fontSize: fontSize.small, fontWeight: '600', color: colors.danger },
  contact: { flexDirection: 'row', alignItems: 'center', marginBottom: spacing.sm },
  contactText: { flex: 1, marginRight: spacing.sm },
  contactLabel: { fontSize: fontSize.body, fontWeight: '600', color: colors.text },
});

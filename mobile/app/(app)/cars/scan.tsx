import { CameraView, useCameraPermissions } from 'expo-camera';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Linking, StyleSheet, Text, View } from 'react-native';

import { claimTag, listCars, type Car } from '../../../src/api/cars';
import { getTagBaseUrl } from '../../../src/api/config';
import { fieldError, toApiError, type ApiError } from '../../../src/api/errors';
import { createScanGate } from '../../../src/cars/scanGate';
import { Button } from '../../../src/components/Button';
import { ErrorBanner } from '../../../src/components/ErrorBanner';
import { Screen } from '../../../src/components/Screen';
import { TextField } from '../../../src/components/TextField';
import { parseTagToken } from '../../../src/lib/parseTagToken';
import { t, useLocale } from '../../../src/i18n';
import { colors, fontSize, radius, spacing } from '../../../src/theme';

/** After a rejected (not-ours) code, ignore the camera for this long so one bad code does not flood the screen. */
const REJECT_PAUSE_MS = 2500;

export default function Scan() {
  useLocale();
  const router = useRouter();
  const { carId: carIdParam } = useLocalSearchParams<{ carId?: string }>();
  const existingCarId = carIdParam ? Number(carIdParam) : undefined;
  const [permission, requestPermission] = useCameraPermissions();

  const [token, setToken] = useState<string | null>(null);
  const [claiming, setClaiming] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [model, setModel] = useState('');
  const [plate, setPlate] = useState('');
  const [already, setAlready] = useState<Car | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{ model?: string; plate?: string }>({});

  // One code is handled at a time: the camera reports the same one many times a second. After a refusal the
  // gate stays closed until the owner taps "Scan again", so the same QR in view cannot claim twice.
  const gate = useRef(createScanGate()).current;
  // Best effort, started on arrival and awaited before a claim, so a tag that is already on one of your cars
  // is reported as such.
  const knownCars = useRef<Promise<Car[]>>(Promise.resolve([]));

  useEffect(() => {
    knownCars.current = listCars().catch(() => []);
  }, []);

  const claim = async (scannedToken: string, details?: { model: string; plate: string }) => {
    setClaiming(true);
    setError(null);
    try {
      const before = await knownCars.current;
      const car = await claimTag(
        existingCarId !== undefined
          ? { token: scannedToken, carId: existingCarId }
          : { token: scannedToken, model: details?.model, plate: details?.plate },
      );
      const wasMine = before.some((c) => c.id === car.id && c.linked);
      if (wasMine) {
        setAlready(car);
        return;
      }
      router.replace({ pathname: '/cars/[id]', params: { id: String(car.id) } });
    } catch (e) {
      const apiError = toApiError(e);
      setError(apiError);
      setFieldErrors({ model: fieldError(apiError, 'model'), plate: fieldError(apiError, 'plate') });
      // A refused tag cannot be fixed by retyping, so go back to the camera (gate still closed, "Scan again"
      // opens it); a form problem keeps the form.
      if (!fieldError(apiError, 'model') && !fieldError(apiError, 'plate')) setToken(null);
    } finally {
      setClaiming(false);
    }
  };

  const onScanned = ({ data }: { data: string }) => {
    if (!gate.open()) return;
    let tagBase: string;
    try {
      tagBase = getTagBaseUrl();
    } catch (e) {
      gate.lock();
      setError(toApiError(e));
      return;
    }
    const scanned = parseTagToken(data, tagBase);
    if (!scanned) {
      gate.pause(REJECT_PAUSE_MS);
      setNotice(t('scan.notWasla'));
      return;
    }
    gate.lock();
    setNotice(null);
    setToken(scanned);
    if (existingCarId !== undefined) void claim(scanned);
  };

  const rescan = () => {
    gate.reset();
    setToken(null);
    setError(null);
    setNotice(null);
    setAlready(null);
    setFieldErrors({});
  };

  if (!permission) {
    return (
      <Screen>
        <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} />
      </Screen>
    );
  }

  if (!permission.granted) {
    return (
      <Screen title={t('scan.permissionTitle')}>
        <Text style={styles.body}>{t('scan.permissionBody')}</Text>
        <View style={styles.stack}>
          {permission.canAskAgain ? <Button label={t('scan.allow')} onPress={requestPermission} /> : null}
          <Button
            label={t('scan.openSettings')}
            variant={permission.canAskAgain ? 'secondary' : 'primary'}
            onPress={() => void Linking.openSettings()}
          />
        </View>
      </Screen>
    );
  }

  if (already) {
    return (
      <Screen>
        <ErrorBanner tone="info" message={t('scan.alreadyYours', { model: already.model })} />
        <View style={styles.stack}>
          <Button
            label={t('scan.open')}
            onPress={() => router.replace({ pathname: '/cars/[id]', params: { id: String(already.id) } })}
          />
          <Button label={t('scan.rescan')} variant="secondary" onPress={rescan} />
        </View>
      </Screen>
    );
  }

  // A code was accepted and the app needs the car's details before it can claim the tag.
  if (token && existingCarId === undefined) {
    const submit = () => {
      const missing = {
        model: model.trim() === '' ? t('field.required') : undefined,
        plate: plate.trim() === '' ? t('field.required') : undefined,
      };
      setFieldErrors(missing);
      if (missing.model || missing.plate) return;
      void claim(token, { model: model.trim(), plate: plate.trim() });
    };
    return (
      <Screen title={t('scan.carTitle')}>
        <Text style={styles.body}>{t('scan.carIntro')}</Text>
        {error && !fieldErrors.model && !fieldErrors.plate ? <ErrorBanner message={error.message} /> : null}
        <TextField label={t('field.model')} value={model} onChangeText={setModel} maxLength={120} error={fieldErrors.model} />
        <TextField
          label={t('field.plate')}
          value={plate}
          onChangeText={setPlate}
          maxLength={32}
          autoCapitalize="characters"
          error={fieldErrors.plate}
        />
        <View style={styles.stack}>
          <Button label={t('scan.link')} loading={claiming} onPress={submit} />
          <Button label={t('scan.rescan')} variant="secondary" disabled={claiming} onPress={rescan} />
        </View>
      </Screen>
    );
  }

  return (
    <Screen>
      <Text style={styles.body}>{t('scan.hint')}</Text>
      <ErrorBanner message={error?.message ?? notice} />
      <View style={styles.cameraBox}>
        <CameraView
          accessibilityLabel={t('scan.cameraLabel')}
          style={styles.camera}
          facing="back"
          barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
          onBarcodeScanned={onScanned}
        />
      </View>
      {error ? <Button label={t('scan.rescan')} variant="secondary" onPress={rescan} /> : null}
      {claiming ? <Text style={styles.body}>{t('common.loading')}</Text> : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  body: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.md },
  stack: { gap: spacing.sm },
  cameraBox: { height: 320, borderRadius: radius, overflow: 'hidden', backgroundColor: colors.text, marginBottom: spacing.md },
  camera: { flex: 1 },
});

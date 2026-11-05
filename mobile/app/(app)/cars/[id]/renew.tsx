import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Pressable, StyleSheet, Text, View } from 'react-native';

import { getCar } from '../../../../src/api/cars';
import { getApiBaseUrl } from '../../../../src/api/config';
import { fieldError, toApiError, type ApiError } from '../../../../src/api/errors';
import { startRenewal } from '../../../../src/api/renewals';
import { Button } from '../../../../src/components/Button';
import { Checkbox } from '../../../../src/components/Checkbox';
import { ErrorBanner } from '../../../../src/components/ErrorBanner';
import { Screen } from '../../../../src/components/Screen';
import { TextField } from '../../../../src/components/TextField';
import { t, useLocale } from '../../../../src/i18n';
import { isFullName, isValidBillingPhone, isValidTckn } from '../../../../src/lib/billing';
import { isAllowedCheckoutUrl } from '../../../../src/lib/checkoutUrl';
import { formatDate } from '../../../../src/lib/format';
import { forgetPending, pendingFor, rememberPending } from '../../../../src/lib/pendingRenewals';
import { pollPayment } from '../../../../src/lib/pollPayment';
import { colors, fontSize, radius, spacing } from '../../../../src/theme';

type Phase = 'form' | 'starting' | 'checking' | 'pending' | 'paid' | 'failed';
type LocalErrors = Partial<Record<'name' | 'address' | 'city' | 'phone' | 'nationalId' | 'legal', string>>;

const POLL_INTERVAL_MS = 3000;
const POLL_AFTER_CHECKOUT_MS = 60000;
const POLL_AGAIN_MS = 30000;

export default function Renew() {
  useLocale();
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const carId = Number(id);

  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const [city, setCity] = useState('');
  const [phone, setPhone] = useState('');
  // The national ID lives in this state only: not in storage, params, logs or any context. It is emptied as
  // soon as the request has been sent (whatever the answer) and whenever the screen loses focus.
  const [nationalId, setNationalId] = useState('');
  const [legal, setLegal] = useState(false);

  // A payment already in progress for this car (left the screen and came back) opens straight on the pending screen.
  const [phase, setPhase] = useState<Phase>(() => (pendingFor(carId) !== null ? 'pending' : 'form'));
  const [showPrivacy, setShowPrivacy] = useState(false);
  const [localErrors, setLocalErrors] = useState<LocalErrors>({});
  const [apiError, setApiError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [expiresAt, setExpiresAt] = useState<string | null>(null);

  const submitting = useRef(false); // a second tap can never start a second payment
  const checking = useRef(false); // one poll run at a time
  const paymentId = useRef<number | null>(pendingFor(carId));
  const checkoutUrl = useRef<string | null>(null);
  const unmounted = useRef(false);
  const runController = useRef<AbortController | null>(null);
  const runId = useRef(0);
  const phaseRef = useRef<Phase>('form');
  phaseRef.current = phase;

  useFocusEffect(
    useCallback(
      () => () => {
        setNationalId('');
      },
      [],
    ),
  );

  useEffect(() => {
    unmounted.current = false;
    return () => {
      unmounted.current = true;
      runController.current?.abort(); // leaving the screen stops the polling
    };
  }, []);

  /**
   * One polling run at a time. `restart` replaces a run that is already going with a fresh one and a new deadline
   * (the owner just came back to the app).
   */
  const check = useCallback(
    async (timeoutMs: number, restart = false) => {
      const id = paymentId.current;
      if (id === null || unmounted.current || (checking.current && !restart)) return;
      runController.current?.abort();
      const controller = new AbortController();
      runController.current = controller;
      const signal = controller.signal;
      const myRun = ++runId.current;
      checking.current = true;
      setApiError(null);
      setPhase('checking');
      try {
        const result = await pollPayment(id, { intervalMs: POLL_INTERVAL_MS, timeoutMs, signal });
        if (signal.aborted) return;
        if (result === 'paid') {
          forgetPending(carId);
          // The new expiry comes from the server, never from this screen's own arithmetic.
          try {
            setExpiresAt((await getCar(carId)).subscription.expires_at);
          } catch {
            setExpiresAt(null);
          }
          if (signal.aborted) return;
          setPhase('paid');
        } else {
          if (result === 'failed') forgetPending(carId);
          setPhase(result);
        }
      } catch (e) {
        if (signal.aborted) return;
        setApiError(toApiError(e));
        setPhase('pending');
      } finally {
        if (runId.current === myRun) checking.current = false;
      }
    },
    [carId],
  );

  // Coming back to the app (on Android the browser call returns at once, so the owner is away while we poll)
  // starts a fresh run with a new deadline whenever the payment is not yet settled.
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active' && (phaseRef.current === 'pending' || phaseRef.current === 'checking')) {
        void check(POLL_AGAIN_MS, true);
      }
    });
    return () => subscription.remove();
  }, [check]);

  const openCheckout = async (url: string) => {
    if (!isAllowedCheckoutUrl(url, getApiBaseUrl(), __DEV__)) {
      setNotice(t('renew.badUrl'));
      setPhase('pending');
      return;
    }
    try {
      // The browser result and the address it ends on are not evidence of anything: only the backend is asked.
      // On iOS this waits until the sheet is closed; on Android it can return at once. Either way what happens
      // next is a poll of the backend, and returning to the app restarts it (see the AppState effect).
      await WebBrowser.openBrowserAsync(url);
    } catch {
      // Whether or not the page opened, what matters is the payment's status on the server.
    }
    if (!unmounted.current) await check(POLL_AFTER_CHECKOUT_MS);
  };

  const validate = (): LocalErrors => {
    const errors: LocalErrors = {};
    if (!name.trim()) errors.name = t('field.required');
    else if (!isFullName(name)) errors.name = t('renew.nameTwoWords');
    if (!address.trim()) errors.address = t('field.required');
    if (!city.trim()) errors.city = t('field.required');
    if (!isValidBillingPhone(phone)) errors.phone = t('renew.phoneInvalid');
    if (!isValidTckn(nationalId)) errors.nationalId = t('renew.idInvalid');
    if (!legal) errors.legal = t('renew.legalRequired');
    return errors;
  };

  const submit = async () => {
    if (submitting.current || phase !== 'form') return;
    const errors = validate();
    setLocalErrors(errors);
    setApiError(null);
    setNotice(null);
    if (Object.keys(errors).length > 0) return;

    submitting.current = true;
    setPhase('starting');
    let url: string | null = null;
    try {
      const started = await startRenewal(carId, {
        billing_name: name.trim(),
        billing_address: address.trim(),
        billing_city: city.trim(),
        billing_phone: phone.trim(),
        billing_national_id: nationalId,
        legal_accepted: legal,
      });
      paymentId.current = started.id;
      rememberPending(carId, started.id);
      checkoutUrl.current = started.redirect_url;
      url = started.redirect_url;
    } catch (e) {
      const error = toApiError(e);
      if (error.code === 'renewal_pending' && error.paymentId !== undefined) {
        // A checkout for this car is already open (this phone or another): follow that payment, do not start one.
        paymentId.current = error.paymentId;
        rememberPending(carId, error.paymentId);
        setApiError(error);
        setPhase('pending');
      } else {
        setApiError(error);
        setPhase('form');
      }
    } finally {
      setNationalId(''); // gone from state as soon as it has been sent
      submitting.current = false;
    }
    if (url) await openCheckout(url);
    else if (paymentId.current !== null) await check(POLL_AGAIN_MS);
  };

  const reopen = () => {
    if (checkoutUrl.current) void openCheckout(checkoutUrl.current);
  };

  const tryAgain = () => {
    paymentId.current = null;
    checkoutUrl.current = null;
    forgetPending(carId);
    setApiError(null);
    setNotice(null);
    setPhase('form');
  };

  const toCars = () => router.dismissTo('/cars');
  const serverField = (field: string) => (apiError ? fieldError(apiError, field) : undefined);
  const bannerMessage = apiError && !apiError.fields ? apiError.message : apiError ? t('errors.validation_failed') : null;

  if (phase === 'paid') {
    return (
      <Screen>
        <Text accessibilityRole="header" style={styles.title}>
          {t('renew.paidTitle')}
        </Text>
        <ErrorBanner tone="info" message={expiresAt ? t('renew.paidBody', { date: formatDate(expiresAt) }) : t('renew.paidNoDate')} />
        <Button label={t('expired.back')} onPress={toCars} />
      </Screen>
    );
  }

  if (phase === 'failed') {
    return (
      <Screen>
        <Text accessibilityRole="header" style={styles.title}>
          {t('renew.failedTitle')}
        </Text>
        <ErrorBanner message={t('renew.failedBody')} />
        <Button label={t('renew.tryAgain')} onPress={tryAgain} />
        <View style={styles.gap} />
        <Button label={t('expired.back')} variant="secondary" onPress={toCars} />
      </Screen>
    );
  }

  if (phase === 'checking' || phase === 'pending') {
    return (
      <Screen>
        <Text accessibilityRole="header" style={styles.title}>
          {t('renew.pendingTitle')}
        </Text>
        <ErrorBanner message={notice} />
        <ErrorBanner message={apiError?.message} />
        <View style={styles.pendingBox}>
          <Text style={styles.pendingText}>{t('renew.pendingBody')}</Text>
        </View>
        {phase === 'checking' ? (
          <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('renew.checking')} />
        ) : (
          <>
            <Button label={t('renew.checkAgain')} onPress={() => void check(POLL_AGAIN_MS)} />
            <View style={styles.gap} />
            {checkoutUrl.current ? <Button label={t('renew.reopen')} variant="secondary" onPress={reopen} /> : null}
            <View style={styles.gap} />
            <Button label={t('expired.back')} variant="link" onPress={toCars} />
          </>
        )}
      </Screen>
    );
  }

  const busy = phase === 'starting';
  return (
    <Screen title={t('renew.title')}>
      <Text style={styles.intro}>{t('renew.intro')}</Text>
      <ErrorBanner message={bannerMessage} />
      <TextField
        label={t('renew.name')}
        value={name}
        onChangeText={setName}
        maxLength={120}
        autoComplete="name"
        textContentType="name"
        error={localErrors.name ?? serverField('billing_name')}
      />
      <TextField
        label={t('renew.address')}
        value={address}
        onChangeText={setAddress}
        maxLength={300}
        autoComplete="street-address"
        textContentType="fullStreetAddress"
        error={localErrors.address ?? serverField('billing_address')}
      />
      <TextField
        label={t('renew.city')}
        value={city}
        onChangeText={setCity}
        maxLength={80}
        autoComplete="postal-address-locality"
        textContentType="addressCity"
        error={localErrors.city ?? serverField('billing_city')}
      />
      <TextField
        label={t('renew.phone')}
        value={phone}
        onChangeText={setPhone}
        keyboardType="phone-pad"
        autoComplete="tel"
        textContentType="telephoneNumber"
        error={localErrors.phone ?? serverField('billing_phone')}
      />
      <TextField
        label={t('renew.nationalId')}
        hint={t('renew.nationalIdWhy')}
        value={nationalId}
        onChangeText={(text) => setNationalId(text.replace(/\D/g, '').slice(0, 11))}
        secureTextEntry
        keyboardType="number-pad"
        maxLength={11}
        // Never offered to a password manager or autofill, never remembered by the keyboard.
        autoComplete="off"
        importantForAutofill="no"
        textContentType="none"
        autoCorrect={false}
        autoCapitalize="none"
        spellCheck={false}
        contextMenuHidden
        error={localErrors.nationalId ?? serverField('billing_national_id')}
      />
      <Text style={styles.draft}>{t('legal.draftBadge')}</Text>
      <Pressable
        accessibilityRole="button"
        accessibilityState={{ expanded: showPrivacy }}
        onPress={() => setShowPrivacy((v) => !v)}
        style={styles.privacyLink}
      >
        <Text style={styles.privacyLinkText}>{t('renew.privacyLink')}</Text>
      </Pressable>
      {showPrivacy ? <Text style={styles.privacyBody}>{t('legal.privacyBody')}</Text> : null}
      <Checkbox
        label={t('renew.legal')}
        checked={legal}
        onChange={setLegal}
        error={localErrors.legal ?? serverField('legal_accepted')}
      />
      <Button label={busy ? t('renew.opening') : t('renew.submit')} loading={busy} onPress={submit} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  title: { fontSize: fontSize.title, fontWeight: '700', color: colors.text, marginBottom: spacing.lg },
  intro: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.lg },
  pendingBox: {
    backgroundColor: colors.primaryTint,
    borderColor: colors.primary,
    borderWidth: 1,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  pendingText: { fontSize: fontSize.body, color: colors.text },
  gap: { height: spacing.sm },
  draft: { fontSize: fontSize.small, fontWeight: '700', color: colors.danger, marginBottom: spacing.xs },
  privacyLink: { minHeight: 44, justifyContent: 'center' },
  privacyLinkText: { color: colors.primaryDark, fontSize: fontSize.body, textDecorationLine: 'underline' },
  privacyBody: { fontSize: fontSize.small, color: colors.text, marginBottom: spacing.md },
});

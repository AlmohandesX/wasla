import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Pressable, StyleSheet, Text, View } from 'react-native';

import { getCar } from '../../../../src/api/cars';
import { allowLocalHttp, getApiBaseUrl } from '../../../../src/api/config';
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
import { forgetPending, expiryFromSeconds, isOver, pendingFor, rememberPending } from '../../../../src/lib/pendingRenewals';
import { pollPayment } from '../../../../src/lib/pollPayment';
import { colors, font, fontSize, radius, spacing } from '../../../../src/theme';

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

  // A checkout already in progress for this car (left the screen and came back) opens straight on the pending screen.
  const initial = useRef(pendingFor(carId));
  const [phase, setPhase] = useState<Phase>(() => (initial.current ? 'pending' : 'form'));
  const [checkedOnce, setCheckedOnce] = useState(false);
  const [showPrivacy, setShowPrivacy] = useState(false);
  const [localErrors, setLocalErrors] = useState<LocalErrors>({});
  const [apiError, setApiError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [expiresAt, setExpiresAt] = useState<string | null>(null);

  const submitting = useRef(false); // a second tap can never start a second payment
  const checking = useRef(false); // one poll run at a time
  const paymentId = useRef<number | null>(initial.current?.paymentId ?? null);
  const checkoutUrl = useRef<string | null>(initial.current?.checkoutUrl ?? null);
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
          setCheckedOnce(true);
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

  // Coming back to a checkout whose lifetime has passed: look once at what the backend says, so a payment that
  // did go through is never answered with an offer to pay again.
  useEffect(() => {
    if (initial.current && initial.current.paymentId !== null && isOver(initial.current)) void check(10000);
  }, [check]);

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

  // Re-render at the moment the checkout lapses, so a screen left open offers a new payment on time.
  const [, setTick] = useState(0);
  const watched = pendingFor(carId);
  const watchedUntil = watched?.expiresAt ?? 0;
  useEffect(() => {
    const wait = watchedUntil - Date.now();
    if (!watchedUntil || wait <= 0) return;
    const timer = setTimeout(() => setTick((n) => n + 1), wait + 50);
    return () => clearTimeout(timer);
  }, [watchedUntil, phase]);

  const openCheckout = async (url: string) => {
    if (!isAllowedCheckoutUrl(url, getApiBaseUrl(), allowLocalHttp())) {
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
      rememberPending(carId, {
        paymentId: started.id,
        checkoutUrl: started.redirect_url,
        expiresAt: expiryFromSeconds(started.checkout_expires_in),
      });
      checkoutUrl.current = started.redirect_url;
      url = started.redirect_url;
    } catch (e) {
      const error = toApiError(e);
      if (error.code === 'renewal_pending') {
        // A checkout for this car is already open: follow that payment if it is ours, otherwise wait it out.
        paymentId.current = error.paymentId ?? null;
        checkoutUrl.current = null;
        rememberPending(carId, {
          paymentId: error.paymentId ?? null,
          checkoutUrl: null,
          expiresAt: expiryFromSeconds(error.checkoutExpiresIn),
        });
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
    setCheckedOnce(false);
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
    const entry = pendingFor(carId);
    const over = entry === null || isOver(entry);
    const minutesLeft = entry ? Math.max(1, Math.ceil((entry.expiresAt - Date.now()) / 60000)) : 1;
    const ours = paymentId.current !== null;
    return (
      <Screen>
        <Text accessibilityRole="header" style={styles.title}>
          {t('renew.pendingTitle')}
        </Text>
        <ErrorBanner message={notice} />
        <ErrorBanner message={apiError?.message} />
        <View style={styles.pendingBox}>
          <Text style={styles.pendingText}>
            {!ours ? t('renew.waitOther', { minutes: minutesLeft }) : over ? t('renew.pendingOver') : t('renew.pendingBody')}
          </Text>
        </View>
        {phase === 'checking' ? (
          <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('renew.checking')} />
        ) : (
          <>
            {ours ? <Button label={t('renew.checkAgain')} onPress={() => void check(POLL_AGAIN_MS)} /> : null}
            <View style={styles.gap} />
            {checkoutUrl.current && !over ? <Button label={t('renew.reopen')} variant="secondary" onPress={reopen} /> : null}
            {over && (!ours || checkedOnce) ? <Button label={t('renew.startNew')} variant="secondary" onPress={tryAgain} /> : null}
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
  title: { fontSize: fontSize.title, fontFamily: font.bold, color: colors.text, marginBottom: spacing.lg },
  intro: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.text, marginBottom: spacing.lg },
  pendingBox: {
    backgroundColor: colors.primaryTint,
    borderColor: colors.primary,
    borderWidth: 1,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  pendingText: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.text },
  gap: { height: spacing.sm },
  draft: { fontSize: fontSize.small, fontFamily: font.bold, color: colors.danger, marginBottom: spacing.xs },
  privacyLink: { minHeight: 44, justifyContent: 'center' },
  privacyLinkText: { color: colors.primaryDark, fontFamily: font.regular, fontSize: fontSize.body, textDecorationLine: 'underline' },
  privacyBody: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.text, marginBottom: spacing.md },
});

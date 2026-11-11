import { useState } from 'react';
import { ActivityIndicator, Image, Pressable, StyleSheet, Text, View } from 'react-native';

import { ApiError, toApiError } from '../api/errors';
import { getGoogleIdToken, isGoogleSignInAvailable } from '../auth/google';
import { useSession } from '../auth/session';
import { t } from '../i18n';
import { colors, font, fontSize, radius, spacing, touchTarget } from '../theme';
import { Button } from './Button';
import { Checkbox } from './Checkbox';
import { ErrorBanner } from './ErrorBanner';
import { PrivacyNotice } from './PrivacyNotice';

/** Google's standard white sign-in button: the "G" mark and the label, nothing else. */
export function GoogleButton({ onPress, loading, disabled, label }: { onPress: () => void; loading?: boolean; disabled?: boolean; label?: string }) {
  const text = label ?? t('google.continue');
  const inactive = disabled || loading;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={text}
      accessibilityState={{ disabled: !!inactive, busy: !!loading }}
      disabled={inactive}
      onPress={onPress}
      style={({ pressed }) => [styles.button, pressed && styles.pressed, inactive && styles.inactive]}
    >
      {loading ? (
        <ActivityIndicator color={colors.primary} />
      ) : (
        <View style={styles.row}>
          <Image source={require('../../assets/images/google-g.png')} style={styles.logo} accessibilityIgnoresInvertColors />
          <Text style={styles.label}>{text}</Text>
        </View>
      )}
    </Pressable>
  );
}

export function OrDivider() {
  return (
    <View style={styles.divider} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <View style={styles.line} />
      <Text style={styles.or}>{t('google.or')}</Text>
      <View style={styles.line} />
    </View>
  );
}

/**
 * "Continue with Google" at the top of the sign-in and register screens. A first-time Google user is shown the
 * privacy notice and must accept it before the account is created; the ID token from the picker is reused for
 * that second request (it stays valid for an hour), so the picker does not open twice.
 *
 * consentGiven: the register screen passes its own checkbox, so an owner who already ticked it is not asked again.
 */
export function GoogleSignInSection({
  consentGiven = false,
  onConsentStep,
}: {
  consentGiven?: boolean;
  /** Told when the privacy step opens or closes, so the screen can hide its email form meanwhile. */
  onConsentStep?: (open: boolean) => void;
}) {
  const { signInWithGoogle } = useSession();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [pendingToken, setPendingToken] = useState<string | null>(null);
  const [consent, setConsent] = useState(false);
  const [consentMissing, setConsentMissing] = useState(false);

  if (!isGoogleSignInAvailable()) return null;

  function openConsent(idToken: string | null) {
    setPendingToken(idToken);
    onConsentStep?.(idToken !== null);
  }

  async function send(idToken: string, withConsent: boolean) {
    try {
      await signInWithGoogle(idToken, withConsent);
      // Signed in: the router leaves this screen.
    } catch (e) {
      const apiError = toApiError(e);
      if (apiError.code === 'privacy_consent_required') {
        openConsent(idToken);
      } else {
        openConsent(null);
        setError(apiError);
      }
    }
  }

  async function start() {
    setBusy(true);
    setError(null);
    try {
      const idToken = await getGoogleIdToken();
      if (idToken) await send(idToken, consentGiven);
    } catch (e) {
      setError(toApiError(e));
    } finally {
      setBusy(false);
    }
  }

  async function accept() {
    if (!consent) {
      setConsentMissing(true);
      return;
    }
    if (!pendingToken) return;
    setBusy(true);
    setError(null);
    await send(pendingToken, true);
    setBusy(false);
  }

  if (pendingToken) {
    return (
      <View style={styles.section}>
        <Text accessibilityRole="header" style={styles.consentTitle}>
          {t('google.consentTitle')}
        </Text>
        <Text style={styles.consentIntro}>{t('google.consentIntro')}</Text>
        <ErrorBanner message={error?.message ?? null} />
        <PrivacyNotice />
        <Checkbox
          label={t('register.consent')}
          checked={consent}
          onChange={(v) => {
            setConsent(v);
            if (v) setConsentMissing(false);
          }}
          error={consentMissing ? t('register.consentRequired') : undefined}
        />
        <Button label={t('google.createAccount')} onPress={accept} loading={busy} />
        <Button
          label={t('google.cancel')}
          variant="link"
          disabled={busy}
          onPress={() => {
            openConsent(null);
            setConsent(false);
            setConsentMissing(false);
          }}
        />
      </View>
    );
  }

  return (
    <View style={styles.section}>
      <ErrorBanner message={error?.message ?? null} />
      <GoogleButton onPress={start} loading={busy} />
      <OrDivider />
    </View>
  );
}

const styles = StyleSheet.create({
  section: { marginBottom: spacing.sm },
  button: {
    minHeight: touchTarget,
    borderRadius: radius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 16,
  },
  pressed: { backgroundColor: colors.primaryTint },
  inactive: { opacity: 0.55 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  logo: { width: 20, height: 20 },
  label: { fontSize: fontSize.body, fontFamily: font.semibold, color: colors.text },
  divider: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginVertical: spacing.md },
  line: { flex: 1, height: 1, backgroundColor: colors.border },
  or: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.textMuted },
  consentTitle: { fontSize: fontSize.title, fontFamily: font.bold, color: colors.text, marginBottom: spacing.xs },
  consentIntro: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.text, marginBottom: spacing.md },
});

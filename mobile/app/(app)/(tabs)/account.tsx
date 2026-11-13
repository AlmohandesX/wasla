import { useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { toApiError } from '../../../src/api/errors';
import { getGoogleIdToken } from '../../../src/auth/google';
import { useSession, type DeleteConfirmation } from '../../../src/auth/session';
import { Button } from '../../../src/components/Button';
import { ErrorBanner } from '../../../src/components/ErrorBanner';
import { GoogleButton } from '../../../src/components/GoogleSignIn';
import { LanguageSwitch } from '../../../src/components/LanguageSwitch';
import { Screen } from '../../../src/components/Screen';
import { TextField } from '../../../src/components/TextField';
import { t, useLocale } from '../../../src/i18n';
import { colors, font, fontSize, radius, spacing } from '../../../src/theme';

export default function Account() {
  useLocale();
  const { user, signOut, deleteAccount } = useSession();
  const [deleting, setDeleting] = useState(false);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | undefined>();
  const inFlight = useRef(false);
  // An owner who only ever signed in with Google has no password to type: they confirm with Google instead.
  const googleOnly = user?.has_password === false;

  // The password prompt never survives leaving the screen.
  useFocusEffect(
    useCallback(
      () => () => {
        setPassword('');
        setDeleting(false);
      },
      [],
    ),
  );

  const cancel = () => {
    setDeleting(false);
    setPassword('');
    setError(null);
    setPasswordError(undefined);
  };

  const confirm = async () => {
    if (inFlight.current) return;
    setError(null);
    setPasswordError(undefined);
    if (!googleOnly && !password) {
      setPasswordError(t('account.passwordRequired'));
      return;
    }
    inFlight.current = true;
    setBusy(true);
    try {
      let confirmation: DeleteConfirmation;
      if (googleOnly) {
        const googleIdToken = await getGoogleIdToken();
        if (!googleIdToken) return; // The Google picker was closed: nothing happens.
        confirmation = { googleIdToken };
      } else {
        confirmation = { password };
      }
      // On success the session ends and the router moves to the sign-in screen, which shows the message.
      await deleteAccount(confirmation);
    } catch (e) {
      const apiError = toApiError(e);
      if (apiError.code === 'invalid_password') setPasswordError(apiError.message);
      else if (apiError.status === 429) {
        setError(t('account.tooMany', { minutes: Math.max(1, Math.ceil((apiError.retryAfter ?? 60) / 60)) }));
      } else setError(apiError.message);
      setPassword('');
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  };

  return (
    <Screen tabbed>
      {user ? <Text style={styles.email}>{t('account.signedInAs', { email: user.email })}</Text> : null}
      <Text accessibilityRole="header" style={styles.section}>
        {t('language.label')}
      </Text>
      <LanguageSwitch />
      <Button label={t('account.signOut')} variant="secondary" onPress={signOut} />

      <View style={styles.danger}>
        {deleting ? (
          <>
            <Text accessibilityRole="header" style={styles.dangerTitle}>
              {t('account.deleteTitle')}
            </Text>
            <Text accessibilityRole="alert" style={styles.warning}>
              {t('account.deleteWarning')}
            </Text>
            <Text style={styles.warningBody}>{t('account.deleteTags')}</Text>
            <ErrorBanner message={error} />
            {googleOnly ? (
              <>
                <Text style={styles.warningBody}>{t('account.deleteGoogleHint')}</Text>
                <GoogleButton label={t('account.deleteWithGoogle')} loading={busy} onPress={confirm} />
              </>
            ) : (
              <>
                <TextField
                  label={t('account.deletePassword')}
                  hint={t('account.deletePasswordHint')}
                  value={password}
                  onChangeText={setPassword}
                  secureTextEntry
                  autoComplete="current-password"
                  textContentType="password"
                  autoCapitalize="none"
                  autoCorrect={false}
                  error={passwordError}
                />
                <Button label={t('account.deleteConfirm')} loading={busy} onPress={confirm} />
              </>
            )}
            <View style={styles.gap} />
            <Button label={t('account.deleteCancel')} variant="secondary" disabled={busy} onPress={cancel} />
          </>
        ) : (
          <Button label={t('account.deleteOpen')} variant="danger" onPress={() => setDeleting(true)} />
        )}
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  email: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.textMuted, marginBottom: spacing.lg },
  section: { fontSize: fontSize.body, fontFamily: font.bold, color: colors.text, marginBottom: spacing.sm },
  danger: {
    marginTop: spacing.xl,
    padding: spacing.md,
    borderWidth: 1,
    borderColor: colors.danger,
    borderRadius: radius,
    backgroundColor: colors.dangerTint,
  },
  dangerTitle: { fontSize: fontSize.body + 2, fontFamily: font.bold, color: colors.danger, marginBottom: spacing.sm },
  warning: { fontSize: fontSize.body, fontFamily: font.semibold, color: colors.danger },
  warningBody: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.text, marginVertical: spacing.sm },
  gap: { height: spacing.sm },
});

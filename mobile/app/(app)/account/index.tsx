import { useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { toApiError } from '../../../src/api/errors';
import { useSession } from '../../../src/auth/session';
import { Button } from '../../../src/components/Button';
import { ErrorBanner } from '../../../src/components/ErrorBanner';
import { LanguageSwitch } from '../../../src/components/LanguageSwitch';
import { Screen } from '../../../src/components/Screen';
import { TextField } from '../../../src/components/TextField';
import { t, useLocale } from '../../../src/i18n';
import { colors, fontSize, radius, spacing } from '../../../src/theme';

export default function Account() {
  useLocale();
  const { user, signOut, deleteAccount } = useSession();
  const [deleting, setDeleting] = useState(false);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | undefined>();
  const inFlight = useRef(false);

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
    if (!password) {
      setPasswordError(t('account.passwordRequired'));
      return;
    }
    inFlight.current = true;
    setBusy(true);
    try {
      // On success the session ends and the router moves to the sign-in screen, which shows the message.
      await deleteAccount(password);
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
    <Screen title={t('account.title')}>
      {user ? <Text style={styles.email}>{t('account.signedInAs', { email: user.email })}</Text> : null}
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
  email: { fontSize: fontSize.body, color: colors.textMuted, marginBottom: spacing.md },
  danger: {
    marginTop: spacing.xl,
    padding: spacing.md,
    borderWidth: 1,
    borderColor: colors.danger,
    borderRadius: radius,
    backgroundColor: colors.dangerTint,
  },
  dangerTitle: { fontSize: fontSize.body + 2, fontWeight: '700', color: colors.danger, marginBottom: spacing.sm },
  warning: { fontSize: fontSize.body, fontWeight: '600', color: colors.danger },
  warningBody: { fontSize: fontSize.small, color: colors.text, marginVertical: spacing.sm },
  gap: { height: spacing.sm },
});

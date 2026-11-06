import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { apiRequest } from '../../src/api/client';
import { ApiError, toApiError } from '../../src/api/errors';
import { useSession } from '../../src/auth/session';
import { Button } from '../../src/components/Button';
import { ErrorBanner } from '../../src/components/ErrorBanner';
import { Screen } from '../../src/components/Screen';
import { t, useLocale } from '../../src/i18n';
import { colors, fontSize, spacing } from '../../src/theme';

export default function VerifyEmail() {
  useLocale();
  const { user, refresh, signOut } = useSession();
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [checking, setChecking] = useState(false);
  const [resending, setResending] = useState(false);

  async function check() {
    setChecking(true);
    setError(null);
    setNotice(null);
    try {
      const me = await refresh();
      // A verified user is moved to the app by the route guard; otherwise say so.
      if (!me.email_verified) setNotice(t('verify.stillUnverified'));
    } catch (e) {
      setError(toApiError(e));
    } finally {
      setChecking(false);
    }
  }

  async function resend() {
    setResending(true);
    setError(null);
    setNotice(null);
    try {
      await apiRequest('auth/email/resend', { method: 'POST' });
      setNotice(t('verify.resent'));
    } catch (e) {
      setError(toApiError(e));
    } finally {
      setResending(false);
    }
  }

  return (
    <Screen title={t('verify.title')}>
      <Text style={styles.intro}>{t('verify.intro', { email: user?.email ?? '' })}</Text>
      <ErrorBanner message={error?.message} />
      <ErrorBanner tone="info" message={notice} />
      <Button label={t('verify.done')} onPress={check} loading={checking} />
      <View style={styles.gap} />
      <Button label={t('verify.resend')} variant="secondary" onPress={resend} loading={resending} />
      <View style={styles.gap} />
      <Button label={t('verify.signOut')} variant="link" onPress={signOut} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  intro: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.md },
  gap: { height: spacing.sm },
});

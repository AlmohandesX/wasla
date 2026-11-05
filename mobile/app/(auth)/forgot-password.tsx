import { Link } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { apiRequest } from '../../src/api/client';
import { ApiError, fieldError } from '../../src/api/errors';
import { Button } from '../../src/components/Button';
import { ErrorBanner } from '../../src/components/ErrorBanner';
import { LinkText } from '../../src/components/LinkText';
import { Screen } from '../../src/components/Screen';
import { TextField } from '../../src/components/TextField';
import { t, useLocale } from '../../src/i18n';
import { colors, fontSize, spacing } from '../../src/theme';

export default function ForgotPassword() {
  useLocale();
  const [email, setEmail] = useState('');
  const [error, setError] = useState<ApiError | null>(null);
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    setSent(false);
    try {
      await apiRequest('auth/forgot-password', { method: 'POST', auth: false, body: { email: email.trim() } });
      setSent(true);
    } catch (e) {
      setError(e instanceof ApiError ? e : null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title={t('forgot.title')}>
      <Text style={styles.intro}>{t('forgot.intro')}</Text>
      <ErrorBanner message={error && !error.fields ? error.message : null} />
      <ErrorBanner tone="info" message={sent ? t('forgot.sent') : null} />
      <TextField
        label={t('field.email')}
        value={email}
        onChangeText={setEmail}
        error={fieldError(error, 'email')}
        autoCapitalize="none"
        autoComplete="email"
        autoCorrect={false}
        keyboardType="email-address"
        textContentType="emailAddress"
        onSubmitEditing={submit}
      />
      <Button label={t('forgot.submit')} onPress={submit} loading={busy} disabled={!email} />
      <View style={styles.links}>
        <Link href="/login" asChild>
          <LinkText label={t('forgot.toLogin')} />
        </Link>
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  intro: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.md },
  links: { marginTop: spacing.md },
});

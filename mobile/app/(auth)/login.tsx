import { Link, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { ApiError, fieldError, messageFor, toApiError } from '../../src/api/errors';
import { useSession } from '../../src/auth/session';
import { Button } from '../../src/components/Button';
import { ErrorBanner } from '../../src/components/ErrorBanner';
import { LanguageSwitch } from '../../src/components/LanguageSwitch';
import { Screen } from '../../src/components/Screen';
import { TextField } from '../../src/components/TextField';
import { t, useLocale } from '../../src/i18n';
import { LinkText } from '../../src/components/LinkText';

export default function Login() {
  useLocale();
  const { signIn, signedOutReason } = useSession();
  const { reset } = useLocalSearchParams<{ reset?: string }>();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await signIn(email, password);
    } catch (e) {
      setError(toApiError(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title={t('login.title')}>
      <LanguageSwitch />
      <ErrorBanner tone="info" message={reset === '1' && !error ? t('reset.done') : null} />
      <ErrorBanner message={error ? (error.fields ? null : error.message) : signedOutReason ? messageFor(signedOutReason) : null} />
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
      />
      <TextField
        label={t('field.password')}
        value={password}
        onChangeText={setPassword}
        error={fieldError(error, 'password')}
        secureTextEntry
        autoCapitalize="none"
        autoComplete="current-password"
        textContentType="password"
        onSubmitEditing={submit}
      />
      <Button label={t('login.submit')} onPress={submit} loading={busy} disabled={!email || !password} />
      <View style={styles.links}>
        <Link href="/forgot-password" asChild>
          <LinkText label={t('login.forgot')} />
        </Link>
        <Link href="/register" asChild>
          <LinkText label={t('login.toRegister')} />
        </Link>
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({ links: { marginTop: 16 } });

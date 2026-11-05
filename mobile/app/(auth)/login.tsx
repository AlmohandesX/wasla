import { Link } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { ApiError, fieldError } from '../../src/api/errors';
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
  const { signIn } = useSession();
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
      setError(e instanceof ApiError ? e : null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title={t('login.title')}>
      <LanguageSwitch />
      <ErrorBanner message={error && !error.fields ? error.message : null} />
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

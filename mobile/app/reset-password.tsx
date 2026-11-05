import { Link, useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { apiRequest } from '../src/api/client';
import { ApiError, fieldError, toApiError } from '../src/api/errors';
import { useSession } from '../src/auth/session';
import { Button } from '../src/components/Button';
import { ErrorBanner } from '../src/components/ErrorBanner';
import { LinkText } from '../src/components/LinkText';
import { Screen } from '../src/components/Screen';
import { TextField } from '../src/components/TextField';
import { t, useLocale } from '../src/i18n';
import { colors, fontSize, spacing } from '../src/theme';

// Outside both route guards on purpose: the emailed link must open this screen whether or not anyone is signed in.
export default function ResetPassword() {
  useLocale();
  const router = useRouter();
  const { user, signOut } = useSession();
  const params = useLocalSearchParams<{ token?: string; email?: string }>();
  const token = typeof params.token === 'string' ? params.token : '';
  const email = typeof params.email === 'string' ? params.email : '';

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<ApiError | null>(null);
  const [mismatch, setMismatch] = useState(false);
  const [busy, setBusy] = useState(false);

  if (!token || !email) {
    return (
      <Screen title={t('reset.title')}>
        <ErrorBanner message={t('reset.incomplete')} />
        {!user ? (
          <Link href="/forgot-password" asChild>
            <LinkText label={t('reset.requestNew')} />
          </Link>
        ) : null}
      </Screen>
    );
  }

  async function submit() {
    if (password !== confirm) {
      setMismatch(true);
      return;
    }
    setMismatch(false);
    setBusy(true);
    setError(null);
    try {
      await apiRequest('auth/reset-password', { method: 'POST', auth: false, body: { email, token, password } });
      // The server revoked every token of this account, so any session on this phone is over too.
      await signOut();
      await new Promise((resolve) => setTimeout(resolve, 0)); // let the route guard see the signed-out state
      router.replace('/login?reset=1');
    } catch (e) {
      setError(toApiError(e));
      setBusy(false);
    }
  }

  return (
    <Screen title={t('reset.title')}>
      <Text style={styles.intro}>{t('reset.intro')}</Text>
      <Text style={styles.email}>{email}</Text>
      <ErrorBanner message={error && !error.fields ? error.message : null} />
      <TextField
        label={t('reset.password')}
        hint={t('field.newPasswordHint')}
        value={password}
        onChangeText={setPassword}
        error={fieldError(error, 'password')}
        secureTextEntry
        autoCapitalize="none"
        autoComplete="new-password"
        textContentType="newPassword"
      />
      <TextField
        label={t('reset.confirm')}
        value={confirm}
        onChangeText={setConfirm}
        error={mismatch ? t('reset.mismatch') : undefined}
        secureTextEntry
        autoCapitalize="none"
        autoComplete="new-password"
        textContentType="newPassword"
        onSubmitEditing={submit}
      />
      <Button label={t('reset.submit')} onPress={submit} loading={busy} disabled={!password || !confirm} />
      {error?.code === 'invalid_reset_token' && !user ? (
        <View style={styles.links}>
          <Link href="/forgot-password" asChild>
            <LinkText label={t('reset.requestNew')} />
          </Link>
        </View>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  intro: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.xs },
  email: { fontSize: fontSize.body, fontWeight: '600', color: colors.text, marginBottom: spacing.md },
  links: { marginTop: spacing.md },
});

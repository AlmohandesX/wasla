import { Link } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { ApiError, fieldError, toApiError } from '../../src/api/errors';
import { useSession } from '../../src/auth/session';
import { Button } from '../../src/components/Button';
import { Checkbox } from '../../src/components/Checkbox';
import { ErrorBanner } from '../../src/components/ErrorBanner';
import { GoogleSignInSection } from '../../src/components/GoogleSignIn';
import { LanguageSwitch } from '../../src/components/LanguageSwitch';
import { LinkText } from '../../src/components/LinkText';
import { PrivacyNotice } from '../../src/components/PrivacyNotice';
import { Screen } from '../../src/components/Screen';
import { TextField } from '../../src/components/TextField';
import { t, useLocale } from '../../src/i18n';
import { spacing } from '../../src/theme';

export default function Register() {
  useLocale();
  const { register } = useSession();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [consent, setConsent] = useState(false);
  const [consentMissing, setConsentMissing] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const [consenting, setConsenting] = useState(false);

  async function submit() {
    if (!consent) {
      setConsentMissing(true);
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await register({ name, email, password, consent });
    } catch (e) {
      setError(toApiError(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen logo title={t('register.title')}>
      <LanguageSwitch />
      <ErrorBanner message={error ? (error.code === 'validation_failed' ? t('errors.validation_failed') : error.message) : null} />
      <GoogleSignInSection consentGiven={consent} onConsentStep={setConsenting} />
      {consenting ? null : (
        <>
          <TextField
            label={t('field.name')}
            value={name}
            onChangeText={setName}
            error={fieldError(error, 'name')}
            autoComplete="name"
            textContentType="name"
          />
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
            hint={t('field.newPasswordHint')}
            value={password}
            onChangeText={setPassword}
            error={fieldError(error, 'password')}
            secureTextEntry
            autoCapitalize="none"
            autoComplete="new-password"
            textContentType="newPassword"
          />

          <PrivacyNotice />
          <Checkbox
            label={t('register.consent')}
            checked={consent}
            onChange={(v) => {
              setConsent(v);
              if (v) setConsentMissing(false);
            }}
            error={consentMissing || fieldError(error, 'privacy_consent') ? t('register.consentRequired') : undefined}
          />

          <Button
            label={t('register.submit')}
            onPress={submit}
            loading={busy}
            disabled={!name || !email || !password}
          />
          <View style={styles.links}>
            <Link href="/login" asChild>
              <LinkText label={t('register.toLogin')} />
            </Link>
          </View>
        </>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  links: { marginTop: spacing.md },
});

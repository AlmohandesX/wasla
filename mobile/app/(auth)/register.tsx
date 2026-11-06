import { Link } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { ApiError, fieldError, toApiError } from '../../src/api/errors';
import { useSession } from '../../src/auth/session';
import { Button } from '../../src/components/Button';
import { Checkbox } from '../../src/components/Checkbox';
import { ErrorBanner } from '../../src/components/ErrorBanner';
import { LanguageSwitch } from '../../src/components/LanguageSwitch';
import { LinkText } from '../../src/components/LinkText';
import { Screen } from '../../src/components/Screen';
import { TextField } from '../../src/components/TextField';
import { t, useLocale } from '../../src/i18n';
import { colors, fontSize, radius, spacing } from '../../src/theme';

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
    <Screen title={t('register.title')}>
      <LanguageSwitch />
      <ErrorBanner message={error ? (error.code === 'validation_failed' ? t('errors.validation_failed') : error.message) : null} />
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

      <View style={styles.notice}>
        <Text accessibilityRole="header" style={styles.noticeTitle}>
          {t('legal.privacyTitle')}
        </Text>
        <Text style={styles.draft}>{t('legal.draftBadge')}</Text>
        <Text style={styles.noticeBody}>{t('legal.privacyBody')}</Text>
      </View>
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
    </Screen>
  );
}

const styles = StyleSheet.create({
  notice: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  noticeTitle: { fontSize: fontSize.body, fontWeight: '700', color: colors.text },
  draft: { fontSize: fontSize.small, fontWeight: '700', color: colors.danger, marginVertical: spacing.xs },
  noticeBody: { fontSize: fontSize.small, color: colors.text, lineHeight: 20 },
  links: { marginTop: spacing.md },
});

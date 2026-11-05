import { StyleSheet, Text } from 'react-native';

import { useSession } from '../../src/auth/session';
import { Button } from '../../src/components/Button';
import { LanguageSwitch } from '../../src/components/LanguageSwitch';
import { Screen } from '../../src/components/Screen';
import { t, useLocale } from '../../src/i18n';
import { colors, fontSize, spacing } from '../../src/theme';

// Placeholder signed-in home. Task 19 replaces it with the car list.
export default function Home() {
  useLocale();
  const { user, signOut } = useSession();
  return (
    <Screen title={t('home.title', { name: user?.name ?? '' })}>
      <LanguageSwitch />
      <Text style={styles.body}>{t('home.placeholder')}</Text>
      <Button label={t('home.signOut')} variant="secondary" onPress={signOut} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  body: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.lg },
});

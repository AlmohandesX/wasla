import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { SessionProvider, useSession } from '../src/auth/session';
import { accessFor } from '../src/auth/guard';
import { Button } from '../src/components/Button';
import { ErrorBanner } from '../src/components/ErrorBanner';
import { t, useLocale } from '../src/i18n';
import { colors, spacing } from '../src/theme';

function Navigator() {
  useLocale();
  const session = useSession();
  const access = accessFor(session);

  if (access === 'loading') {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} />
      </View>
    );
  }

  if (access === 'error') {
    // The saved session could not be checked (offline, or the app's server address is wrong). The token is kept
    // so a flaky network does not sign the owner out; they can retry or sign out.
    return (
      <View style={styles.center}>
        <ErrorBanner message={session.bootError?.message} />
        <Button label={t('common.retry')} onPress={session.retryBoot} />
        <View style={styles.gap} />
        <Button label={t('boot.signOut')} variant="secondary" onPress={session.signOut} />
      </View>
    );
  }

  // Expo Router protected routes: owner screens exist only for a signed-in, verified user, and the auth group
  // (sign in, register, verify email) only otherwise. When the state changes the router moves the user itself.
  // reset-password is outside both: the emailed link must work signed in or out.
  return (
    <Stack screenOptions={{ headerShown: false }}>
      <Stack.Protected guard={access === 'app'}>
        <Stack.Screen name="(app)" />
      </Stack.Protected>
      <Stack.Protected guard={access !== 'app'}>
        <Stack.Screen name="(auth)" />
      </Stack.Protected>
      <Stack.Screen name="index" />
      <Stack.Screen name="reset-password" />
    </Stack>
  );
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <SessionProvider>
        <StatusBar style="dark" />
        <Navigator />
      </SessionProvider>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.lg, backgroundColor: colors.surface },
  gap: { height: spacing.sm },
});

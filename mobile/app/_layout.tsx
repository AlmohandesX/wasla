import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { ErrorBanner } from '../src/components/ErrorBanner';
import { Button } from '../src/components/Button';
import { SessionProvider, useSession } from '../src/auth/session';
import { t, useLocale } from '../src/i18n';
import { colors, spacing } from '../src/theme';

function Navigator() {
  useLocale();
  const { user, ready, bootError, retryBoot } = useSession();

  if (!ready) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} />
      </View>
    );
  }

  if (bootError) {
    // The saved session could not be checked (offline). Keep the token and let the owner retry.
    return (
      <View style={styles.center}>
        <ErrorBanner message={bootError.message} />
        <Button label={t('common.retry')} onPress={retryBoot} />
      </View>
    );
  }

  const signedIn = !!user;
  const verified = !!user?.email_verified;

  // Expo Router protected routes: owner screens exist only for a signed-in, verified user, and the
  // auth group (sign in, register, verify email) only otherwise. When the state changes, the router
  // moves the user to the right group itself.
  return (
    <Stack screenOptions={{ headerShown: false }}>
      <Stack.Protected guard={signedIn && verified}>
        <Stack.Screen name="(app)" />
      </Stack.Protected>
      <Stack.Protected guard={!(signedIn && verified)}>
        <Stack.Screen name="(auth)" />
      </Stack.Protected>
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
});

import {
  Manrope_400Regular,
  Manrope_500Medium,
  Manrope_600SemiBold,
  Manrope_700Bold,
  Manrope_800ExtraBold,
  useFonts,
} from '@expo-google-fonts/manrope';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { SessionProvider, useSession } from '../src/auth/session';
import { accessFor } from '../src/auth/guard';
import { Button } from '../src/components/Button';
import { ErrorBanner } from '../src/components/ErrorBanner';
import { Logo } from '../src/components/Logo';
import { t, useLocale } from '../src/i18n';
import { colors, spacing } from '../src/theme';

// Keep the navy splash up until Manrope is ready, so no screen flashes in the system font.
void SplashScreen.preventAutoHideAsync();

function Navigator() {
  useLocale();
  const session = useSession();
  const access = accessFor(session);

  if (access === 'loading') {
    // Continues the navy splash while the saved session is checked.
    return (
      <View style={[styles.center, styles.boot]}>
        <StatusBar style="light" />
        <Logo tone="white" width={220} />
        <ActivityIndicator
          size="large"
          color={colors.accent}
          style={styles.spinner}
          accessibilityLabel={t('common.loading')}
        />
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
  const [fontsLoaded, fontError] = useFonts({
    Manrope_400Regular,
    Manrope_500Medium,
    Manrope_600SemiBold,
    Manrope_700Bold,
    Manrope_800ExtraBold,
  });
  const ready = fontsLoaded || !!fontError;

  useEffect(() => {
    if (ready) void SplashScreen.hideAsync();
  }, [ready]);

  // If the font fails to load the app still starts, in the system font.
  if (!ready) return null;

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
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.lg, backgroundColor: colors.background },
  boot: { backgroundColor: colors.primary },
  spinner: { marginTop: spacing.lg },
  gap: { height: spacing.sm },
});

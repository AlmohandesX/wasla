import { Stack } from 'expo-router';

import { t, useLocale } from '../../src/i18n';
import { colors } from '../../src/theme';

export default function AppLayout() {
  useLocale();
  return (
    <Stack
      screenOptions={{
        headerTintColor: colors.primaryDark,
        headerTitleStyle: { color: colors.text },
        headerBackTitle: t('common.back'),
      }}
    >
      <Stack.Screen name="index" options={{ headerShown: false }} />
      <Stack.Screen name="cars/index" options={{ headerShown: false }} />
      <Stack.Screen name="cars/scan" options={{ title: t('scan.title') }} />
      <Stack.Screen name="cars/[id]/index" options={{ title: t('cars.title') }} />
      <Stack.Screen name="cars/[id]/settings" options={{ title: t('detail.settings') }} />
      <Stack.Screen name="cars/[id]/status" options={{ title: t('detail.status') }} />
      <Stack.Screen name="cars/[id]/transfer" options={{ title: t('detail.transfer') }} />
    </Stack>
  );
}

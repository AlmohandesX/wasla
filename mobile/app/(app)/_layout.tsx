import { Stack, useRouter } from 'expo-router';
import { useEffect } from 'react';

import { t, useLocale } from '../../src/i18n';
import { enablePush, listenForChatTaps } from '../../src/push/register';
import { colors } from '../../src/theme';

export default function AppLayout() {
  useLocale();
  const router = useRouter();

  // Once per signed-in session: register this device if notification permission was already given. The
  // permission prompt itself is left to the chats screen so it does not appear at cold start.
  useEffect(() => {
    void enablePush(false);
  }, []);

  // A tap on a chat notification opens that thread.
  useEffect(
    () => listenForChatTaps((threadId) => router.push({ pathname: '/chats/[id]', params: { id: String(threadId) } })),
    [router],
  );

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
      <Stack.Screen name="chats/index" options={{ title: t('chats.title') }} />
      <Stack.Screen name="chats/[id]" options={{ title: t('chat.title') }} />
      <Stack.Screen name="cars/scan" options={{ title: t('scan.title') }} />
      <Stack.Screen name="cars/[id]/index" options={{ title: t('cars.title') }} />
      <Stack.Screen name="cars/[id]/settings" options={{ title: t('detail.settings') }} />
      <Stack.Screen name="cars/[id]/status" options={{ title: t('detail.status') }} />
      <Stack.Screen name="cars/[id]/reminders" options={{ title: t('reminders.title') }} />
      <Stack.Screen name="cars/[id]/transfer" options={{ title: t('detail.transfer') }} />
    </Stack>
  );
}

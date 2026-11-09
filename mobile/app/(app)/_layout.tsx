import { Stack, usePathname, useRouter } from 'expo-router';
import { useEffect, useRef } from 'react';

import { setExpiredHandler } from '../../src/api/client';
import { useSession } from '../../src/auth/session';
import { t, useLocale } from '../../src/i18n';
import { enablePush, listenForChatTaps } from '../../src/push/register';
import { colors, font } from '../../src/theme';

export default function AppLayout() {
  useLocale();
  const router = useRouter();
  const { user } = useSession();
  const userId = user?.id;
  const pathname = usePathname();
  const pathRef = useRef(pathname);
  pathRef.current = pathname;

  // Any request for one car that comes back subscription_expired opens the hard stop for that car, and only
  // that car. The screen that asked is replaced so Back does not return to a dead end.
  useEffect(() => {
    setExpiredHandler((carId) => {
      const base = `/cars/${carId}`;
      const here = pathRef.current;
      if (here === `${base}/expired` || here === `${base}/renew`) return;
      const target = { pathname: '/cars/[id]/expired', params: { id: String(carId) } } as const;
      if (here.startsWith(`${base}/`) || here === base) router.replace(target);
      else router.push(target);
    });
    return () => setExpiredHandler(null);
  }, [router]);

  // Once per signed-in session: register this device if notification permission was already given. The
  // permission prompt itself is left to the chats screen so it does not appear at cold start.
  useEffect(() => {
    if (userId !== undefined) void enablePush(false, userId);
  }, [userId]);

  // A tap on a chat notification opens that thread.
  useEffect(
    () => listenForChatTaps((threadId) => router.push({ pathname: '/chats/[id]', params: { id: String(threadId) } })),
    [router],
  );

  return (
    <Stack
      screenOptions={{
        // A solid white header with the screen laid out below it. On iOS 26+ the native header is otherwise a
        // see-through "Liquid Glass" bar that screens draw underneath, which put chat text behind the Back
        // button and the status bar. Setting both options keeps the opaque bar on every iOS version.
        headerTransparent: false,
        headerStyle: { backgroundColor: colors.surface },
        headerShadowVisible: true,
        contentStyle: { backgroundColor: colors.background },
        headerTintColor: colors.primaryDark,
        headerTitleStyle: { color: colors.text, fontFamily: font.bold },
        headerBackTitle: t('common.back'),
      }}
    >
      <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
      <Stack.Screen name="chats/[id]" options={{ title: t('chat.title') }} />
      <Stack.Screen name="cars/scan" options={{ title: t('scan.title') }} />
      <Stack.Screen name="cars/[id]/index" options={{ title: t('cars.title') }} />
      <Stack.Screen name="cars/[id]/settings" options={{ title: t('detail.settings') }} />
      <Stack.Screen name="cars/[id]/status" options={{ title: t('detail.status') }} />
      <Stack.Screen name="cars/[id]/reminders" options={{ title: t('reminders.title') }} />
      <Stack.Screen name="cars/[id]/transfer" options={{ title: t('detail.transfer') }} />
      <Stack.Screen name="cars/[id]/expired" options={{ title: '' }} />
      <Stack.Screen name="cars/[id]/renew" options={{ title: t('renew.title') }} />
    </Stack>
  );
}

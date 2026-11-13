import Ionicons from '@expo/vector-icons/Ionicons';
import { Tabs } from 'expo-router/js-tabs';
import type { ComponentProps } from 'react';

import { t, useLocale } from '../../../src/i18n';
import { colors, font, navyHeader } from '../../../src/theme';

type IconName = ComponentProps<typeof Ionicons>['name'];
// The tab bar passes React Native's wider colour type; it only ever gives us the tint strings set below.
type IconColor = ComponentProps<typeof Ionicons>['color'];

/** Filled icon for the selected tab, outline otherwise, so the active tab does not rely on colour alone. */
function tabIcon(active: IconName, inactive: IconName) {
  return function TabIcon({ focused, color, size }: { focused: boolean; color: unknown; size: number }) {
    return <Ionicons name={focused ? active : inactive} color={color as IconColor} size={size} />;
  };
}

// The owner's three main places. Detail screens (a car, a chat thread, the scanner) stay in the parent stack,
// so they open over the tabs with a back button and the tab bar hidden. Chats and Account show the shared navy
// header; Home draws its own navy band with the logo and greeting.
export default function TabsLayout() {
  useLocale();
  return (
    <Tabs
      screenOptions={{
        ...navyHeader,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.textMuted,
        tabBarLabelStyle: { fontSize: 12, fontFamily: font.semibold },
        tabBarStyle: { backgroundColor: colors.surface, borderTopColor: colors.divider },
        sceneStyle: { backgroundColor: colors.background },
      }}
    >
      <Tabs.Screen
        name="home"
        options={{ title: t('tabs.home'), headerShown: false, tabBarIcon: tabIcon('home', 'home-outline') }}
      />
      <Tabs.Screen
        name="chats"
        options={{ title: t('chats.title'), tabBarIcon: tabIcon('chatbubbles', 'chatbubbles-outline') }}
      />
      <Tabs.Screen
        name="account"
        options={{ title: t('account.title'), tabBarIcon: tabIcon('person-circle', 'person-circle-outline') }}
      />
    </Tabs>
  );
}

import Ionicons from '@expo/vector-icons/Ionicons';
import type { ComponentProps } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { colors, font, spacing, touchTarget } from '../theme';

type IconName = ComponentProps<typeof Ionicons>['name'];

export type CarAction = {
  key: string;
  icon: IconName;
  /** Short label shown under the icon. */
  label: string;
  /** Full name read by screen readers when the short label is not enough. */
  accessibilityLabel?: string;
  onPress: () => void;
};

/** Height of the bar above the phone's bottom inset, so a screen can keep its last item clear of it. */
export const CAR_ACTION_BAR_HEIGHT = 60;

/**
 * The car screen's bottom bar: one icon and short label per place the owner goes for this car. It looks like
 * the Home tab bar but each item opens a screen on top, with a back button, rather than switching tabs.
 */
export function CarActionBar({ actions }: { actions: CarAction[] }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={[styles.bar, { paddingBottom: insets.bottom }]}>
      {actions.map((action) => (
        <Pressable
          key={action.key}
          accessibilityRole="button"
          accessibilityLabel={action.accessibilityLabel ?? action.label}
          onPress={action.onPress}
          style={({ pressed }) => [styles.item, pressed && styles.itemPressed]}
        >
          <Ionicons name={action.icon} size={24} color={colors.primary} />
          <Text style={styles.label} numberOfLines={1}>
            {action.label}
          </Text>
        </Pressable>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    backgroundColor: colors.surface,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.divider,
  },
  item: {
    flex: 1,
    minHeight: Math.max(touchTarget, CAR_ACTION_BAR_HEIGHT),
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.xs,
    gap: 2,
  },
  itemPressed: { backgroundColor: colors.primaryTint },
  label: { fontSize: 12, fontFamily: font.semibold, color: colors.text },
});

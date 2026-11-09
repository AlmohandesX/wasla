import Ionicons from '@expo/vector-icons/Ionicons';
import type { ComponentProps } from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';

import { colors, font, fontSize, spacing } from '../theme';

export const FAB_HEIGHT = 56;

type Props = {
  icon: ComponentProps<typeof Ionicons>['name'];
  /** Short visible text next to the icon. */
  label: string;
  /** Full description for screen readers when the visible label is shortened. */
  accessibilityLabel?: string;
  onPress: () => void;
};

/** The screen's main action, floating in the bottom-right corner just above the tab bar. */
export function Fab({ icon, label, accessibilityLabel, onPress }: Props) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      onPress={onPress}
      style={({ pressed }) => [styles.fab, pressed && styles.pressed]}
    >
      <Ionicons name={icon} size={24} color={colors.primary} />
      <Text style={styles.label}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  fab: {
    position: 'absolute',
    right: spacing.md,
    bottom: spacing.md,
    height: FAB_HEIGHT,
    minWidth: FAB_HEIGHT,
    paddingHorizontal: spacing.lg - spacing.xs,
    borderRadius: FAB_HEIGHT / 2,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    // Signal orange with navy content, like the logo's connector (5.6:1).
    backgroundColor: colors.accent,
    // Lifted off the list so it reads as floating; Android uses elevation, iOS and web the shadow.
    elevation: 6,
    shadowColor: colors.text,
    shadowOpacity: 0.25,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 4 },
  },
  pressed: { backgroundColor: colors.accentPressed },
  label: { color: colors.primary, fontSize: fontSize.body, fontFamily: font.bold },
});
